<?php

namespace App\Livewire\Console;

use App\Models\Budget;
use App\Models\User;
use App\Livewire\Concerns\WithDataTable;
use App\Services\NetworkService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The people using the product.
 *
 * The prototype had to invent this population, because one browser's
 * localStorage is not a member base. Here it is the real users table joined to
 * their real budgets — which means the most important column on the screen is
 * FEASIBLE: how many people we have told, honestly, that their month does not
 * add up.
 *
 * That number going up is not a product failure. It is the product working on
 * a population that is genuinely stretched, and the thing to watch is whether
 * those members come back.
 *
 * No individual figures are exposed beyond what operations needs to do the job.
 */
#[Layout('layouts.console')]
class Members extends Component
{
    use WithDataTable;

    #[Url]
    public string $filter = 'all';

    #[Url]
    public string $q = '';

    public function render(NetworkService $network)
    {
        $month = date('Y-m');

        $query = User::query()
            ->where('role', 'member')
            ->with(['profile.location'])
            ->withCount(['priceReports', 'transactions'])
            ->when($this->q !== '', fn ($q) => $q->where('name', 'like', '%' . $this->q . '%'));

        /*
         * One query for every budget, not one per member.
         *
         * Looking each up inside the map read perfectly well and ran 125
         * queries against a 120-member cohort — the classic N+1, invisible at
         * demo scale and fatal at real scale. ScaleTest catches it now.
         */
        $budgets = Budget::where('month', $month)->get()->keyBy('user_id');

        $members = $query->get()->map(function (User $u) use ($budgets) {
            $budget = $budgets->get($u->id);

            return [
                'user'     => $u,
                'budget'   => $budget,
                'feasible' => $budget?->feasible,
                'joined'   => $u->created_at,
            ];
        });

        $matching = match ($this->filter) {
            'stretched' => $members->filter(fn ($m) => $m['feasible'] === false),
            'balanced'  => $members->filter(fn ($m) => $m['feasible'] === true),
            'nobudget'  => $members->filter(fn ($m) => $m['budget'] === null),
            default     => $members,
        };

        /*
         * Sorting reaches into three different shapes — an Eloquent model, a
         * possibly-null budget, and a computed flag — which is exactly why the
         * trait asks the component for the value rather than guessing.
         */
        $table = $this->paginate($matching->values()->all(), function (array $row, string $column) {
            $b = $row['budget'];

            return match ($column) {
                'name'      => $row['user']->name,
                'area'      => $row['user']->profile?->location?->label ?? '',
                'household' => $row['user']->profile?->household_size ?? 0,
                'income'    => $b?->income ?? -1,
                'floor'     => $b?->floor_total ?? -1,
                'goals'     => $b?->goal_monthly ?? -1,
                // Short first when descending: those are the ones to look at.
                'month'     => $b === null ? -1 : ($b->feasible ? 0 : $b->shortfall),
                'entries'   => $row['user']->transactions_count,
                'prices'    => $row['user']->price_reports_count,
                'joined'    => $row['joined']->getTimestamp(),
                default     => 0,
            };
        });

        /*
         * Strict comparisons, deliberately.
         *
         * `feasible` is a tri-state — true, false, or null when no budget has
         * been built yet — and Collection::where() is loose by default, so
         * where('feasible', false) happily swept up every member who had never
         * opened the app and then read ->shortfall on their missing budget.
         */
        $short = $members->filter(fn ($m) => $m['feasible'] === false);

        $counts = [
            'all'       => $members->count(),
            'stretched' => $short->count(),
            'balanced'  => $members->filter(fn ($m) => $m['feasible'] === true)->count(),
            'nobudget'  => $members->filter(fn ($m) => $m['budget'] === null)->count(),
        ];

        return view('livewire.console.members', [
            'rows'      => $table['rows'],
            'meta'      => $table,
            'counts'    => $counts,
            'health'    => $network->health(),
            'shortfall' => $short->sum(fn ($m) => $m['budget']->shortfall),
            'reporting' => $members->filter(fn ($m) => $m['user']->price_reports_count > 0)->count(),
        ])->layoutData([
            'page'         => 'members',
            'title'        => 'Members',
            'pendingCount' => $network->health()['flagged'],
        ]);
    }
}
