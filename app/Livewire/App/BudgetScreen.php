<?php

namespace App\Livewire\App;

use App\Domain\Money;
use App\Models\Goal;
use App\Services\BudgetService;
use App\Services\TradeoffService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Every naira, and why.
 *
 * The screen is ordered the way the allocator actually thinks: what came in,
 * what was taken off the top, what the floor costs at local prices, what the
 * goals need, and only then what is left to decide about. A budget presented
 * as a pie chart hides that ordering, which is where all the meaning is.
 *
 * When the month does not balance, the trade-off list IS the screen. Nothing
 * here silently reduces a goal to make the arithmetic look neat.
 */
#[Layout('layouts.app')]
class BudgetScreen extends Component
{
    /** Which line has its derivation expanded. */
    public ?int $open = null;

    public string $applied = '';

    public function toggle(int $lineId): void
    {
        $this->open = $this->open === $lineId ? null : $lineId;
    }

    /**
     * Apply a trade-off the member chose.
     *
     * The member decides; the engine never quietly applies one of these on
     * their behalf, which is the difference between a co-pilot and autopilot.
     */
    public function apply(string $type, int $goalId, int $months = 0): void
    {
        $goal   = Goal::where('user_id', Auth::id())->findOrFail($goalId);
        $months = max(1, $months);

        if ($type === 'extend-goal') {
            $goal->increment('months_left', $months);
            $this->applied = sprintf('Gave "%s" %d more month%s.', $goal->label, $months, $months === 1 ? '' : 's');
        } else {
            $goal->update(['paused' => true]);
            $this->applied = sprintf('Paused "%s" for this month.', $goal->label);
        }

        app(BudgetService::class)->rebuild(Auth::user()->fresh());
    }

    public function render(BudgetService $budgets, TradeoffService $tradeoffs)
    {
        $user   = Auth::user()->load('profile.location');
        $budget = $budgets->current($user);

        // Grouped in pipeline order, because the order IS the explanation.
        $stages = [
            'obligation'    => ['Taken off the top',        'Fixed commitments. These are not negotiable, so they go first.'],
            'floor'         => ['The floor, priced locally', 'What it actually costs to feed and move your household here.'],
            'goal'          => ['Towards your goals',        'Derived from the target and the deadline, not a round number.'],
            'discretionary' => ['What is left to decide',    'Shared out by your preferences, within limits.'],
        ];

        $groups = [];
        foreach ($stages as $kind => [$label, $blurb]) {
            $lines = $budget->lines->where('kind', $kind)->sortByDesc('amount')->values();
            if ($lines->isEmpty()) {
                continue;
            }
            $groups[] = [
                'kind'  => $kind,
                'label' => $label,
                'blurb' => $blurb,
                'lines' => $lines,
                'total' => (int) $lines->sum('amount'),
            ];
        }

        return view('livewire.app.budget', [
            'budget'    => $budget,
            'groups'    => $groups,
            'options'   => $budget->feasible ? [] : $tradeoffs->rank($user, $budget->shortfall),
            'allocated' => (int) $budget->lines->sum('amount'),
        ])->layoutData([
            'page'  => 'home',
            'title' => 'Your budget',
            'sub'   => date('F Y') . ' · ' . Money::fmt($budget->income) . ' expected',
            'back'  => route('app.dashboard'),
        ]);
    }
}
