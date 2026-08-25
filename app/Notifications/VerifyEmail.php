<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail as BaseVerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The verification email.
 *
 * Laravel's default says "Please click the button below to verify your email
 * address" and nothing else, which gives a person no reason to bother. This one
 * leads with what is already waiting for them — their budget is built before
 * they ever see a screen — and is explicit that verifying is not a gate.
 *
 * It is not a gate on purpose. A budget is useful before an email round-trip
 * completes, and locking somebody out of the thing they just spent three steps
 * building is how you lose them at the door.
 */
class VerifyEmail extends BaseVerifyEmail
{
    public function toMail($notifiable): MailMessage
    {
        $url = $this->verificationUrl($notifiable);

        return (new MailMessage)
            ->subject('Confirm your email — MoneyPilot')
            ->greeting('Hello ' . explode(' ', $notifiable->name)[0] . ',')
            ->line('Your MoneyPilot budget is already built and waiting. Confirming this address '
                 . 'means we can reach you if something in your month needs attention.')
            ->action('Confirm my email', $url)
            ->line('You can keep using MoneyPilot either way — this is not a lock on your account.')
            ->line('If you did not create a MoneyPilot account, you can ignore this message. '
                 . 'Nothing will happen and the link expires on its own.')
            ->salutation('— MoneyPilot, Lagos');
    }
}
