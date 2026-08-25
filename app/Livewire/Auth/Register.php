<?php

namespace App\Livewire\Auth;

use App\Domain\Categories;
use App\Domain\Money;
use App\Models\IncomeSource;
use App\Models\Location;
use App\Models\Obligation;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\WelcomeAboard;
use App\Services\BudgetService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Getting started.
 *
 * Three steps, and every one of them earns its place by changing the budget
 * that comes out at the end. There is no "tell us about yourself" step,
 * because the engine has no use for the answer.
 *
 * The account is created only at the last step. A half-finished sign-up should
 * not leave a row behind that can never be signed into, and asking for money
 * questions before an email address is what makes the first two steps feel
 * like a tool rather than a form.
 */
#[Layout('layouts.guest')]
class Register extends Component
{
    public int $step = 1;

    /* --- step 1: where and who --- */
    public ?int $locationId = null;
    public int $householdSize = 1;
    public int $dependents = 0;
    public int $tripsPerDay = 2;
    public int $daysOut = 22;

    /* --- step 2: money --- */
    public string $incomeAmount = '';
    public string $incomeReliability = 'certain';
    public string $rentAmount = '';

    /* --- step 3: the account --- */
    public string $name = '';
    public string $email = '';
    public string $password = '';
    public string $passwordConfirmation = '';

    public function mount(): void
    {
        $this->locationId ??= Location::orderBy('label')->value('id');
    }

    public function next(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'locationId'    => 'required|integer|exists:locations,id',
                'householdSize' => 'required|integer|min:1|max:15',
                'dependents'    => 'required|integer|min:0|max:14',
                'tripsPerDay'   => 'required|integer|min:0|max:10',
                'daysOut'       => 'required|integer|min:0|max:31',
            ]);

            if ($this->dependents >= $this->householdSize) {
                $this->addError('dependents', 'Dependents must be fewer than the household total — '
                    . 'at least one person has to be earning or travelling.');
                return;
            }
        }

        if ($this->step === 2) {
            $this->validate([
                'incomeAmount'      => 'required|string|max:30',
                'incomeReliability' => 'required|in:certain,likely,variable',
                'rentAmount'        => 'nullable|string|max:30',
            ]);

            if (Money::toKobo($this->incomeAmount) <= 0) {
                $this->addError('incomeAmount', 'Roughly how much comes in each month? For example 85,000.');
                return;
            }
        }

        $this->step = min(3, $this->step + 1);
    }

    public function back(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function register(BudgetService $budgets): void
    {
        $this->validate([
            'name'                 => 'required|string|max:80',
            'email'                => 'required|email|max:190|unique:users,email',
            'password'             => ['required', 'confirmed:passwordConfirmation', Password::min(8)],
        ], [
            'email.unique'        => 'There is already an account with that address. Sign in instead.',
            'password.confirmed'  => 'The two passwords do not match.',
        ]);

        $user = User::create([
            'name'     => $this->name,
            'email'    => $this->email,
            // Hashed by the model cast, server-side. The prototype's
            // client-side PBKDF2 verified a password where the attacker was.
            'password' => $this->password,
            'role'     => 'member',
            'title'    => 'Member',
        ]);

        Profile::create([
            'user_id'        => $user->id,
            'location_id'    => $this->locationId,
            'household_size' => $this->householdSize,
            'dependents'     => $this->dependents,
            'trips_per_day'  => $this->tripsPerDay,
            'days_out'       => $this->daysOut,
            'weights'        => Categories::defaultWeights(),
        ]);

        IncomeSource::create([
            'user_id'     => $user->id,
            'label'       => 'Main income',
            'amount'      => Money::toKobo($this->incomeAmount),
            'reliability' => $this->incomeReliability,
        ]);

        if (($rent = Money::toKobo($this->rentAmount)) > 0) {
            Obligation::create([
                'user_id'  => $user->id,
                'label'    => 'Rent (spread monthly)',
                'amount'   => $rent,
                'category' => 'housing',
                'due_day'  => 1,
            ]);
        }

        // Sends the verification link. It is not a gate — see routes/web.php.
        event(new Registered($user));

        // The first budget is built before they ever see a screen, so the app
        // opens on their own figures rather than on an empty state.
        $budget = $budgets->rebuild($user);

        // And the first email carries those figures rather than a greeting,
        // because the figures are the reason to come back. Queued, so SMTP
        // never sits between somebody and the screen they just earned.
        $user->notify(new WelcomeAboard($budget));

        Auth::login($user, remember: true);
        session()->regenerate();

        $this->redirectRoute('app.dashboard', navigate: true);
    }

    /** A live preview of the floor, so step 1 visibly does something. */
    public function render(\App\Services\PriceService $prices)
    {
        $location = $this->locationId ? Location::find($this->locationId) : null;

        $floor = $location
            ? \App\Domain\Basket::cost($prices->priceResolver($location), [
                'household_size' => $this->householdSize,
                'dependents'     => $this->dependents,
                'trips_per_day'  => $this->tripsPerDay,
                'days_out'       => $this->daysOut,
            ])
            : null;

        return view('livewire.auth.register', [
            'locations' => Location::orderBy('label')->get(),
            'floor'     => $floor,
            'location'  => $location,
        ]);
    }
}
