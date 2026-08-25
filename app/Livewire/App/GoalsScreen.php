<?php

namespace App\Livewire\App;

use App\Domain\Goals as GoalMath;
use App\Domain\Money;
use App\Models\Goal;
use App\Services\BudgetService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * What you are saving towards.
 *
 * A goal here is not a wish with a progress bar. It is an input to the
 * allocation engine: the monthly contribution is DERIVED from the target and
 * the deadline, funded before anything discretionary, and never quietly
 * reduced when the month is tight. If it cannot be funded, the budget says the
 * month does not balance — which is the point.
 *
 * Changing a goal therefore rebuilds the budget. Nothing is left stale.
 */
#[Layout('layouts.app')]
class GoalsScreen extends Component
{
    #[Validate('required|string|max:60')]
    public string $label = '';

    #[Validate('required|string|max:30')]
    public string $target = '';

    #[Validate('required|integer|min:1|max:120')]
    public int $months = 6;

    #[Validate('nullable|string|max:30')]
    public string $saved = '';

    public bool $adding = false;

    public string $flash = '';

    public function add(BudgetService $budgets): void
    {
        $this->validate();

        $target = Money::toKobo($this->target);

        if ($target <= 0) {
            $this->addError('target', 'How much do you need in total? For example 180,000.');
            return;
        }

        $saved = Money::toKobo($this->saved);

        if ($saved > $target) {
            $this->addError('saved', 'You already have more than the target — check these two figures.');
            return;
        }

        Goal::create([
            'user_id'         => Auth::id(),
            'label'           => $this->label,
            'target'          => $target,
            'saved'           => $saved,
            'months_left'     => $this->months,
            // Kept so the screen can show how far the goal has drifted from
            // the pace it was set at, rather than only its current demand.
            'original_months' => $this->months,
        ]);

        $budget = $budgets->rebuild(Auth::user()->fresh());

        $this->flash = $budget->feasible
            ? sprintf('"%s" is funded at %s a month from now on.',
                $this->label, Money::fmt(GoalMath::required([
                    'target' => $target, 'saved' => $saved, 'months_left' => $this->months,
                ])['monthly']))
            : sprintf('"%s" has been added, but your month no longer balances — the budget screen '
                    . 'lists what would close the gap.', $this->label);

        $this->reset('label', 'target', 'saved', 'adding');
        $this->months = 6;
    }

    /**
     * Money actually put aside.
     *
     * Keyed by goal id rather than one shared field, so two goals open at once
     * cannot pick up each other's figure — which is exactly the sort of bug
     * that moves somebody's money to the wrong place.
     *
     * @var array<int,string>
     */
    public array $amounts = [];

    public function contribute(int $goalId, BudgetService $budgets): void
    {
        $goal = Goal::where('user_id', Auth::id())->findOrFail($goalId);
        $kobo = Money::toKobo($this->amounts[$goalId] ?? '');

        if ($kobo <= 0) {
            return;
        }

        $goal->update(['saved' => min($goal->target, $goal->saved + $kobo)]);
        $budgets->rebuild(Auth::user()->fresh());

        $this->flash = Money::fmt($kobo) . ' added to "' . $goal->label . '".';
    }

    public function togglePause(int $goalId, BudgetService $budgets): void
    {
        $goal = Goal::where('user_id', Auth::id())->findOrFail($goalId);
        $goal->update(['paused' => ! $goal->paused]);

        $budgets->rebuild(Auth::user()->fresh());

        $this->flash = $goal->paused
            ? sprintf('"%s" is paused. It is not being funded this month.', $goal->label)
            : sprintf('"%s" is active again.', $goal->label);
    }

    public function extend(int $goalId, int $months, BudgetService $budgets): void
    {
        $goal = Goal::where('user_id', Auth::id())->findOrFail($goalId);
        $goal->increment('months_left', max(1, $months));

        $budgets->rebuild(Auth::user()->fresh());

        $this->flash = sprintf('"%s" now needs %s a month.',
            $goal->label, Money::fmt($goal->fresh()->requirement()['monthly']));
    }

    public function render(BudgetService $budgets)
    {
        $user   = Auth::user();
        $budget = $budgets->current($user);

        $goals = $user->goals()->orderBy('paused')->orderByDesc('target')->get()
            ->map(fn (Goal $g) => [
                'model'       => $g,
                'requirement' => $g->requirement(),
                'drift'       => GoalMath::drift($g->toEngineArray()),
            ]);

        return view('livewire.app.goals', [
            'goals'  => $goals,
            'budget' => $budget,
            'funded' => $budget->goal_monthly,
        ])->layoutData([
            'page'  => 'home',
            'title' => 'Goals',
            'sub'   => $goals->count()
                ? Money::fmt($budget->goal_monthly) . ' a month set aside'
                : 'Nothing set yet',
            'back'  => route('app.dashboard'),
        ]);
    }
}
