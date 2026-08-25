<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use App\Models\PriceReport;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * One sign-in page for all three roles.
 *
 * The prototype made the visitor pick a side before it would show them
 * anything, which is a question only the system cares about. Here the email
 * decides the role and the destination — the person just signs in.
 *
 * Unlike the prototype, this is a real credential check: the password is
 * hashed with Argon2/bcrypt server-side, and rate limiting means a wrong
 * guess actually costs something.
 */
#[Layout('layouts.guest')]
class Login extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = true;

    public function login(): void
    {
        $this->validate();

        // Throttled per email+IP. Client-side auth could never do this.
        $key = 'login:' . mb_strtolower($this->email) . '|' . request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => 'Too many attempts. Try again in '
                    . RateLimiter::availableIn($key) . ' seconds.',
            ]);
        }

        if (!Auth::attempt(['email' => $this->email, 'password' => $this->password], $this->remember)) {
            RateLimiter::hit($key, 60);

            // The same message for an unknown address and a wrong password,
            // so this cannot be used to discover who has an account.
            throw ValidationException::withMessages([
                'email' => 'Those details do not match an account.',
            ]);
        }

        RateLimiter::clear($key);
        session()->regenerate();

        $this->redirectRoute(Auth::user()->homeRoute(), navigate: true);
    }

    /** One tap into a demo account. The password is still genuinely checked. */
    public function demo(string $email): void
    {
        $this->email = $email;
        $this->password = 'moneypilot';
        $this->login();
    }

    /** Shown on the sign-in panel: the network is real, and this proves it. */
    #[Computed]
    public function reportCount(): int
    {
        return PriceReport::count();
    }

    public function render()
    {
        return view('livewire.auth.login', [
            'demos' => [
                ['email' => 'chidi@student.unilag.ng', 'name' => 'Chidi Okonkwo', 'role' => 'Member',
                 'blurb' => 'Feasible but tight. Laptop fund in mid-flight, live rice price shock.'],
                ['email' => 'amaka.eze@gmail.com', 'name' => 'Amaka Eze', 'role' => 'Member',
                 'blurb' => 'Her numbers do not fit. Shows the trade-off engine doing real work.'],
                ['email' => 'a.okafor@moneypilot.ng', 'name' => 'Adaeze Okafor', 'role' => 'Operations',
                 'blurb' => 'Moderation queue, coverage map, contributor trust.'],
                ['email' => 't.bakare@moneypilot.ng', 'name' => 'Tunde Bakare', 'role' => 'Owner',
                 'blurb' => 'Growth, activation, retention — is MoneyPilot working?'],
            ],
        ]);
    }
}
