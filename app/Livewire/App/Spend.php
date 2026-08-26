<?php

namespace App\Livewire\App;

use App\Domain\Categories;
use App\Domain\Money;
use App\Models\Item;
use App\Models\Transaction;
use App\Services\BudgetService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * The spending log.
 *
 * The ledger is append-only. A mistake is corrected by writing an equal and
 * opposite entry that points back at the original, never by editing or
 * deleting the row — so the history a member (or a support agent) sees is the
 * history that actually happened. It costs one extra row and buys the ability
 * to answer "why did my budget change?" months later.
 */
#[Layout('layouts.app')]
class Spend extends Component
{
    #[Validate('required|string|max:40')]
    public string $amount = '';

    #[Validate('required|string')]
    public string $category = 'food';

    #[Validate('nullable|string|max:120')]
    public string $note = '';

    /** Optional: which item this was, so the entry can also carry a price. */
    #[Validate('nullable|string')]
    public string $itemSlug = '';

    public string $flash = '';

    public function log(): void
    {
        $this->validate();

        $kobo = Money::toKobo($this->amount);

        if ($kobo <= 0) {
            $this->addError('amount', 'Enter an amount, for example 2,500.');
            return;
        }

        Transaction::create([
            'user_id'     => Auth::id(),
            'occurred_at' => now(),
            'amount'      => $kobo,
            'category'    => $this->category,
            'item_id'     => $this->itemSlug ? Item::where('slug', $this->itemSlug)->value('id') : null,
            'note'        => $this->note ?: Categories::label($this->category),
            'source'      => 'manual',
        ]);

        $this->flash = Money::fmt($kobo) . ' logged to ' . Categories::label($this->category) . '.';
        $this->reset('amount', 'note', 'itemSlug');
    }

    /**
     * The entry awaiting confirmation, if any.
     *
     * A reversal is permanent — it writes a second row that can never be taken
     * back — so it gets a sheet that names the entry and the amount rather than
     * happening on a stray tap next to the amount the member just typed.
     */
    public ?int $confirming = null;

    public function askReverse(int $id): void
    {
        $this->confirming = $id;
    }

    public function cancelReverse(): void
    {
        $this->confirming = null;
    }

    public function confirmReverse(): void
    {
        if ($this->confirming !== null) {
            $this->reverse($this->confirming);
            $this->confirming = null;
        }
    }

    /** Correct an entry by reversing it, not by rewriting history. */
    public function reverse(int $id): void
    {
        $tx = Transaction::where('user_id', Auth::id())
            ->whereNull('reverses_id')
            ->findOrFail($id);

        if (Transaction::where('reverses_id', $tx->id)->exists()) {
            $this->flash = 'That entry has already been corrected.';
            return;
        }

        Transaction::create($tx->reversal());

        $this->flash = 'Reversed ' . Money::fmt($tx->amount) . '. The original entry is kept.';
    }

    public function render(BudgetService $budgets)
    {
        $user     = Auth::user()->load('profile.location');
        $position = $budgets->monthPosition($user);

        $transactions = $user->transactions()
            ->with('item')
            ->whereBetween('occurred_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        $reversed = $transactions->whereNotNull('reverses_id')->pluck('reverses_id')->all();

        // Grouped by day: "what did I spend yesterday" is the question people
        // actually ask, and a flat list cannot answer it.
        $days = $transactions
            ->groupBy(fn ($t) => $t->occurred_at->toDateString())
            ->map(fn ($rows, $date) => [
                'date'  => \Carbon\Carbon::parse($date),
                'total' => (int) $rows->sum('amount'),
                'rows'  => $rows,
            ])
            ->values();

        // Category bars, biggest first — the shape of the month at a glance.
        $chips = [];
        foreach (Categories::LIVING as $id) {
            $spent = $position['spend'][$id] ?? 0;
            if ($spent <= 0) {
                continue;
            }
            $allocated = $position['alloc'][$id] ?? 0;
            $chips[] = [
                'id'        => $id,
                'label'     => Categories::label($id),
                'spent'     => $spent,
                'allocated' => $allocated,
                'health'    => Categories::health($allocated, $spent),
                'pct'       => $allocated > 0 ? min(100, $spent / $allocated * 100) : 100,
            ];
        }
        usort($chips, fn ($a, $b) => $b['spent'] <=> $a['spent']);

        $monthTotal = (int) $transactions->sum('amount');

        return view('livewire.app.spend', [
            'position'   => $position,
            'days'       => $days,
            'chips'      => $chips,
            'reversed'   => $reversed,
            'categories' => Categories::LIVING,
            'items'      => Item::orderBy('label')->get(['slug', 'label']),
            'pending'    => $this->confirming
                ? $transactions->firstWhere('id', $this->confirming)
                : null,
            'monthTotal' => $monthTotal,
        ])->layoutData([
            'page'  => 'spend',
            'title' => 'Spending',
            'sub'   => Money::fmt($monthTotal) . ' so far in ' . now()->format('F'),
        ]);
    }
}
