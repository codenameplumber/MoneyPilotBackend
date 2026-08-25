<?php

namespace App\Livewire\App;

use App\Domain\Categories;
use App\Domain\Money;
use App\Models\IncomeSource;
use App\Models\Location;
use App\Models\Obligation;
use App\Models\Profile;
use App\Services\BudgetService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The inputs to everything else.
 *
 * Nothing on this screen is a preference in the cosmetic sense. Household size
 * changes what the food basket costs; the LGA changes which price reports the
 * basket is costed from; the reliability of an income source changes how much
 * of it the engine is willing to plan against. So every save rebuilds the
 * budget, and the screen says what changed as a result.
 *
 * The preference weights only ever move DISCRETIONARY money. They cannot touch
 * the floor — a slider that could starve somebody is not a feature.
 */
#[Layout('layouts.app')]
class ProfileScreen extends Component
{
    /* --- household --- */
    public ?int $locationId = null;
    public int $householdSize = 1;
    public int $dependents = 0;
    public int $tripsPerDay = 2;
    public int $daysOut = 22;

    /** @var array<string,int> */
    public array $weights = [];

    /* --- adding rows --- */
    public string $incomeLabel = '';
    public string $incomeAmount = '';
    public string $incomeReliability = 'certain';

    public string $obLabel = '';
    public string $obAmount = '';
    public string $obCategory = 'housing';
    public int $obDueDay = 1;

    public string $flash = '';

    public function mount(): void
    {
        $p = Auth::user()->profile;

        if ($p) {
            $this->locationId    = $p->location_id;
            $this->householdSize = $p->household_size;
            $this->dependents    = $p->dependents;
            $this->tripsPerDay   = $p->trips_per_day;
            $this->daysOut       = $p->days_out;
            $this->weights       = $p->weights ?: Categories::defaultWeights();
        } else {
            $this->weights = Categories::defaultWeights();
        }
    }

    public function saveHousehold(BudgetService $budgets): void
    {
        $this->validate([
            'locationId'    => 'nullable|integer|exists:locations,id',
            'householdSize' => 'required|integer|min:1|max:15',
            'dependents'    => 'required|integer|min:0|max:14',
            'tripsPerDay'   => 'required|integer|min:0|max:10',
            'daysOut'       => 'required|integer|min:0|max:31',
        ]);

        if ($this->dependents >= $this->householdSize) {
            $this->addError('dependents',
                'At least one person in the household has to be earning or travelling — '
                . 'dependents must be fewer than the total.');
            return;
        }

        $before = $budgets->current(Auth::user())->floor_total;

        Profile::updateOrCreate(['user_id' => Auth::id()], [
            'location_id'    => $this->locationId,
            'household_size' => $this->householdSize,
            'dependents'     => $this->dependents,
            'trips_per_day'  => $this->tripsPerDay,
            'days_out'       => $this->daysOut,
            'weights'        => $this->weights,
        ]);

        $after = $budgets->rebuild(Auth::user()->fresh())->floor_total;
        $delta = $after - $before;

        $this->flash = $delta === 0
            ? 'Saved. Your floor is unchanged.'
            : sprintf('Saved. The cost of your essentials %s by %s, priced from local reports.',
                $delta > 0 ? 'rose' : 'fell', Money::fmt(abs($delta)));
    }

    public function saveWeights(BudgetService $budgets): void
    {
        Profile::updateOrCreate(['user_id' => Auth::id()], ['weights' => $this->weights]);
        $budgets->rebuild(Auth::user()->fresh());

        $this->flash = 'Priorities saved. Only discretionary money moved — your food and transport '
                     . 'floor is not affected by these.';
    }

    public function addIncome(BudgetService $budgets): void
    {
        $this->validate([
            'incomeLabel'       => 'required|string|max:60',
            'incomeAmount'      => 'required|string|max:30',
            'incomeReliability' => 'required|in:certain,likely,variable',
        ]);

        $kobo = Money::toKobo($this->incomeAmount);

        if ($kobo <= 0) {
            $this->addError('incomeAmount', 'How much, per month? For example 85,000.');
            return;
        }

        IncomeSource::create([
            'user_id'     => Auth::id(),
            'label'       => $this->incomeLabel,
            'amount'      => $kobo,
            'reliability' => $this->incomeReliability,
        ]);

        $budget = $budgets->rebuild(Auth::user()->fresh());

        $this->flash = $budget->income === $budget->gross_income
            ? Money::fmt($kobo) . ' added.'
            : sprintf('%s added. The engine is planning against %s of your %s total, because some '
                    . 'of it is not certain.',
                Money::fmt($kobo), Money::fmt($budget->income), Money::fmt($budget->gross_income));

        $this->reset('incomeLabel', 'incomeAmount');
        $this->incomeReliability = 'certain';
    }

    public function removeIncome(int $id, BudgetService $budgets): void
    {
        IncomeSource::where('user_id', Auth::id())->findOrFail($id)->delete();
        $budgets->rebuild(Auth::user()->fresh());
        $this->flash = 'Income source removed and your budget rebuilt.';
    }

    public function addObligation(BudgetService $budgets): void
    {
        $this->validate([
            'obLabel'    => 'required|string|max:60',
            'obAmount'   => 'required|string|max:30',
            'obCategory' => 'required|in:housing,fees,debt,utilities',
            'obDueDay'   => 'required|integer|min:1|max:28',
        ]);

        $kobo = Money::toKobo($this->obAmount);

        if ($kobo <= 0) {
            $this->addError('obAmount', 'How much, per month? For example 25,000.');
            return;
        }

        Obligation::create([
            'user_id'  => Auth::id(),
            'label'    => $this->obLabel,
            'amount'   => $kobo,
            'category' => $this->obCategory,
            'due_day'  => $this->obDueDay,
        ]);

        $budget = $budgets->rebuild(Auth::user()->fresh());

        $this->flash = $budget->feasible
            ? Money::fmt($kobo) . ' committed. Your month still balances.'
            : sprintf('%s committed. Your month no longer balances — it is short %s.',
                Money::fmt($kobo), Money::fmt($budget->shortfall));

        $this->reset('obLabel', 'obAmount');
        $this->obDueDay = 1;
    }

    public function removeObligation(int $id, BudgetService $budgets): void
    {
        Obligation::where('user_id', Auth::id())->findOrFail($id)->delete();
        $budgets->rebuild(Auth::user()->fresh());
        $this->flash = 'Commitment removed and your budget rebuilt.';
    }

    public function render(BudgetService $budgets)
    {
        $user   = Auth::user()->load(['profile.location', 'incomeSources', 'obligations']);
        $budget = $budgets->current($user);

        return view('livewire.app.profile', [
            'user'          => $user,
            'budget'        => $budget,
            'locations'     => Location::orderBy('label')->get(),
            'discretionary' => Categories::discretionary(),
            'reportCount'   => $user->priceReports()->count(),
        ])->layoutData([
            'page'  => 'home',
            'title' => 'Profile',
            'sub'   => 'The inputs everything else is built from',
            'back'  => route('app.dashboard'),
        ]);
    }
}
