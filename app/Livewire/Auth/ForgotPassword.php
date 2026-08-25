<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * "I have forgotten my password."
 *
 * The response is deliberately identical whether or not the address exists.
 * Telling a stranger "no account with that email" turns this form into a free
 * membership oracle — paste a leaked address list, learn who banks with you.
 * The cost of the ambiguity is one confused user; the cost of the alternative
 * is everybody's membership becoming public.
 */
#[Layout('layouts.guest')]
class ForgotPassword extends Component
{
    #[Validate('required|email')]
    public string $email = '';

    public bool $sent = false;

    public function send(): void
    {
        $this->validate();

        // Throttled per address and IP. Without this the form is a way to have
        // us email anybody, repeatedly, on somebody else's behalf.
        $key = 'reset:' . mb_strtolower($this->email) . '|' . request()->ip();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages([
                'email' => 'That is a lot of attempts. Try again in '
                    . RateLimiter::availableIn($key) . ' seconds.',
            ]);
        }

        RateLimiter::hit($key, 300);

        // The status is ignored on purpose — see the class comment.
        Password::sendResetLink(['email' => $this->email]);

        $this->sent = true;
    }

    public function render()
    {
        return view('livewire.auth.forgot-password');
    }
}
