<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword as BaseResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The password reset email.
 *
 * The line that matters is the last one. A reset email is the single most
 * effective phishing template in existence, so this one states plainly what
 * MoneyPilot will never ask for — and the product is built so that promise is
 * keepable: there is no bank connection to compromise in the first place.
 */
class ResetPassword extends BaseResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $minutes = config('auth.passwords.users.expire', 60);

        return (new MailMessage)
            ->subject('Reset your MoneyPilot password')
            ->greeting('Hello ' . explode(' ', $notifiable->name)[0] . ',')
            ->line('Somebody asked to reset the password for this account. If it was you, the '
                 . 'button below will let you choose a new one.')
            ->action('Choose a new password', $this->resetUrl($notifiable))
            ->line("This link stops working in {$minutes} minutes.")
            ->line('If it was not you, ignore this email — your password has not changed, and '
                 . 'nobody can get in without this link.')
            ->line('MoneyPilot will never ask for your bank login, your card details or your BVN. '
                 . 'We have no way to move your money and never will.')
            ->salutation('— MoneyPilot, Lagos');
    }
}
