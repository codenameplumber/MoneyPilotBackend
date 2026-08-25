<?php

namespace App\Livewire\Auth;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * "We have sent you a link."
 *
 * Reachable, never forced. Nothing in MoneyPilot sits behind `verified`
 * middleware: a budget is useful before an email round-trip completes, and
 * locking somebody out of the thing they just spent three steps building is how
 * you lose them at the door.
 */
#[Layout('layouts.guest')]
class VerifyNotice extends Component
{
    public string $flash = '';

    public function resend(): void
    {
        if (Auth::user()->hasVerifiedEmail()) {
            $this->redirectRoute(Auth::user()->homeRoute(), navigate: true);
            return;
        }

        $key = 'verify:' . Auth::id();

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $this->flash = 'We have sent a few already — check your spam folder, then try again in '
                         . RateLimiter::availableIn($key) . ' seconds.';
            return;
        }

        RateLimiter::hit($key, 300);

        Auth::user()->sendEmailVerificationNotification();

        $this->flash = 'Sent. It should arrive at ' . Auth::user()->email . ' within a minute.';
    }

    public function render()
    {
        return view('livewire.auth.verify-notice');
    }
}
