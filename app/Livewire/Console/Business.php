<?php

namespace App\Livewire\Console;

use App\Models\Budget;
use App\Models\CoachQuery;
use App\Models\PriceReport;
use App\Models\StatementAnalysis;
use App\Models\Transaction;
use App\Models\User;
use App\Services\NetworkService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Is MoneyPilot working?
 *
 * Not "are people signing up" — that measures marketing. The questions here are
 * the ones an owner has to answer honestly:
 *
 *   · Does the network compound? Reports per active member is the number that
 *     decides whether coverage improves on its own or has to be bought.
 *   · Does the product change anything? Members who log spending after their
 *     first budget are the ones for whom it became a habit rather than a demo.
 *   · Does it help the people it is hardest to help? An infeasible member who
 *     comes back is the strongest evidence the honest branch was right.
 *
 * Every figure is counted from the tables. Nothing here is a projection, and
 * anything that would be one is left off the screen.
 */
#[Layout('layouts.console')]
class Business extends Component
{
    public function render(NetworkService $network)
    {
        $month  = date('Y-m');
        $health = $network->health();

        $members    = User::where('role', 'member')->count();
        $withBudget = Budget::where('month', $month)->distinct()->count('user_id');
        $infeasible = Budget::where('month', $month)->where('feasible', false)->count();

        // Activation: a budget alone is a demo. Logging spending afterwards is
        // the first sign somebody is actually running their month on this.
        $activated = User::where('role', 'member')
            ->whereHas('transactions', fn ($q) => $q->where('source', '!=', 'seed'))
            ->count();

        $reporting = User::where('role', 'member')->has('priceReports')->count();

        $growth = PriceReport::selectRaw('DATE_FORMAT(reported_at, "%Y-%m") m, COUNT(*) n,
                                          COUNT(DISTINCT COALESCE(reporter_key, CONCAT("u", user_id))) c')
            ->where('reported_at', '>=', now()->subMonths(6))
            ->groupBy('m')
            ->orderBy('m')
            ->get();

        return view('livewire.console.business', [
            'health'     => $health,
            'members'    => $members,
            'withBudget' => $withBudget,
            'infeasible' => $infeasible,
            'activated'  => $activated,
            'reporting'  => $reporting,
            'growth'     => $growth,
            'coachQueries' => CoachQuery::count(),
            'coachByIntent' => CoachQuery::selectRaw('intent, COUNT(*) n')
                ->groupBy('intent')->orderByDesc('n')->limit(6)->get(),
            'llmShare' => CoachQuery::count()
                ? CoachQuery::where('used_llm', true)->count() / CoachQuery::count()
                : 0.0,
            'statements' => StatementAnalysis::count(),
            'timingFound' => StatementAnalysis::whereNotNull('payday')->where('exposed_total', '>', 0)->count(),
            'entries'    => Transaction::where('source', '!=', 'seed')->count(),
            // Reports per active contributor: below 1 the network needs buying,
            // above it the network buys itself.
            'perContributor' => $health['active'] ? $health['last30'] / $health['active'] : 0.0,
        ])->layoutData([
            'page'         => 'business',
            'title'        => 'Owner dashboard',
            'pendingCount' => $health['flagged'],
        ]);
    }
}
