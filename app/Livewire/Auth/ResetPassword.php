<?php

namespace App\Livewire\Auth;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Component;

/**
 * Choosing a new password from an emailed link.
 *
 * Two things happen on success besides the password changing: the remember
 * token is rotated, which signs the account out everywhere it was remembered,
 * and the session is regenerated. If somebody is resetting because their
 * account was taken, leaving the intruder's session alive would make the reset
 * pointless.
 */
#[Layout('layouts.guest')]
class ResetPassword extends Component
{
    public string $token = '';

    #[Validate('required|email')]
    public string $email = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(string $token): void
    {
        $this->token = $token;
        $this->email = (string) request('email', '');
    }

    /* Named `save`, not `reset` — Livewire\Component::reset() already exists
       and overriding it with a different signature is a fatal error. */
    public function save(): void
    {
        $this->validate([
            'token'    => 'required',
            'email'    => 'required|email',
            'password' => ['required', 'confirmed:passwordConfirmation', PasswordRule::min(8)],
        ], [
            'password.confirmed' => 'The two passwords do not match.',
        ]);

        $status = Password::reset(
            [
                'email'                 => $this->email,
                'password'              => $this->password,
                'password_confirmation' => $this->passwordConfirmation,
                'token'                 => $this->token,
            ],
            function ($user) {
                $user->forceFill([
                    'password'       => $this->password,
                    // Rotating this signs out every "keep me signed in" device.
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            $this->addError('email', match ($status) {
                Password::INVALID_TOKEN => 'That reset link has expired or has already been used. '
                                         . 'Ask for a new one.',
                Password::INVALID_USER  => 'That reset link is not valid.',
                default                 => 'That did not work. Ask for a new link and try again.',
            });

            return;
        }

        Auth::attempt(['email' => $this->email, 'password' => $this->password]);
        session()->regenerate();

        $this->redirectRoute(Auth::user()?->homeRoute() ?? 'login', navigate: true);
    }

    public function render()
    {
        return view('livewire.auth.reset-password');
    }
}
