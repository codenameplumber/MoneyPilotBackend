<?php

namespace App\Livewire\App;

use App\Domain\Categories;
use App\Domain\Money;
use App\Models\PriceReport;
use App\Services\BudgetService;
use App\Services\InsightService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The member's home screen.
 *
 * Ordered by what needs attention rather than by what is easiest to compute:
 * an infeasible budget shouts first, then pace, then the category meters.
 *
 * All the arithmetic happens in BudgetService and the Domain classes. This
 * component only asks and arranges — which is what keeps a Livewire class
 * from quietly becoming the place the business logic lives.
 */
#[Layout('layouts.app')]
class Dashboard extends Component
{
    public function rebuild(BudgetService $budgets): void
    {
        $budgets->rebuild(Auth::user());
        $this->dispatch('budget-rebuilt');
    }

    public function render(BudgetService $budgets, InsightService $insights)
    {
        $user     = Auth::user()->load('profile.location');
        $position = $budgets->monthPosition($user);
        $budget   = $position['budget'];

        // Categories a person decides about day to day, ranked by what needs
        // looking at rather than alphabetically.
        $rows = [];
        foreach (Categories::LIVING as $id) {
            $allocated = $position['alloc'][$id] ?? 0;
            if ($allocated <= 0) {
                continue;
            }
            $spent  = $position['spend'][$id] ?? 0;
            $health = Categories::health($allocated, $spent);

            $rows[] = [
                'id'        => $id,
                'label'     => Categories::label($id),
                'allocated' => $allocated,
                'spent'     => $spent,
                'health'    => $health,
                'pct'       => $allocated > 0 ? min(100, ($spent / $allocated) * 100) : 0,
                'note'      => $spent > $allocated
                    ? Money::fmt($spent - $allocated) . ' over'
                    : Money::fmt($allocated - $spent) . ' left',
            ];
        }

        $rank = ['crit' => 0, 'warn' => 1, 'good' => 2, 'quiet' => 3];
        usort($rows, fn ($a, $b) => [$rank[$a['health']['state']], -$a['allocated']]
                                <=> [$rank[$b['health']['state']], -$b['allocated']]);

        $hour = (int) date('G');

        /*
         * The plan against the income that has to carry it.
         *
         * The donut was captioned "Where the money is going" and centred on the
         * plan total, which on a short month is a number larger than anything
         * the member will actually receive — ₦354k of "going" against ₦254k of
         * income, presented as though the money existed. The chart is honest
         * about composition; it needed a line making it honest about scale.
         */
        $planned = array_sum(array_filter($position['alloc'], fn ($v) => $v > 0));

        return view('livewire.app.dashboard', [
            'user'        => $user,
            'budget'      => $budget,
            'position'    => $position,
            'rows'        => array_slice($rows, 0, 6),
            'planned'     => $planned,
            // How many of the listed areas need looking at, so the section can
            // say so once instead of making the member count red bars.
            'overCount'   => count(array_filter($rows, fn ($r) => $r['health']['state'] === 'crit')),
            'areaCount'   => count($rows),
            'goals'       => $user->goals()->where('paused', false)->get(),
            'recent'      => $user->transactions()->latest('occurred_at')->limit(4)->get(),
            'totalSpent'  => array_sum($position['spend']),
            'reportCount' => PriceReport::count(),
            // The four detectors — price shock, burn rate, overpayment, goal
            // drift. Price shock is the one no competitor can compute.
            'insights'    => $insights->detect($user),
            'slices'      => $this->slices($position['alloc']),
        ])->layoutData([
            'page'  => 'home',
            'title' => ($hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'))
                       . ', ' . explode(' ', $user->name)[0],
            'sub'   => date('F Y') . ' · day ' . $position['elapsed'] . ' of ' . $position['total_days'],
        ]);
    }
    /**
     * Donut slices: the five largest categories, then a collapsed tail.
     *
     * A donut is defensible at six slices with a labelled legend and
     * indefensible at fourteen, so the tail becomes one "Everything else"
     * rather than a fan of unreadable slivers.
     */
    private function slices(array $alloc): array
    {
        $rows = [];
        foreach ($alloc as $id => $value) {
            if ($value > 0) $rows[] = ['id' => $id, 'label' => Categories::label($id), 'value' => $value];
        }

        usort($rows, fn ($a, $b) => $b['value'] <=> $a['value']);

        $top  = array_slice($rows, 0, 5);
        $rest = array_slice($rows, 5);

        if ($rest) {
            $top[] = ['id' => 'other', 'label' => 'Everything else',
                      'value' => array_sum(array_column($rest, 'value'))];
        }

        foreach ($top as $i => $row) {
            $top[$i]['color'] = $row['id'] === 'other'
                ? 'var(--text-3)'
                : 'var(--c' . (($i % 6) + 1) . ')';
        }

        return $top;
    }
}
