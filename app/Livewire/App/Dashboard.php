<?php

namespace App\Livewire\App;

use App\Domain\Categories;
use App\Domain\Money;
use App\Models\PriceReport;
use App\Services\BudgetService;
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

    public function render(BudgetService $budgets)
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

        return view('livewire.app.dashboard', [
            'user'        => $user,
            'budget'      => $budget,
            'position'    => $position,
            'rows'        => array_slice($rows, 0, 6),
            'goals'       => $user->goals()->where('paused', false)->get(),
            'recent'      => $user->transactions()->latest('occurred_at')->limit(4)->get(),
            'totalSpent'  => array_sum($position['spend']),
            'reportCount' => PriceReport::count(),
        ])->layoutData([
            'page'  => 'home',
            'title' => ($hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening'))
                       . ', ' . explode(' ', $user->name)[0],
            'sub'   => date('F Y') . ' · day ' . $position['elapsed'] . ' of ' . $position['total_days'],
        ]);
    }
}
