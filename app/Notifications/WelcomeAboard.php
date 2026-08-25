<?php

namespace App\Notifications;

use App\Domain\Money;
use App\Models\Budget;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The first email, sent once the first budget exists.
 *
 * It carries the member's actual figures rather than a welcome message, because
 * the figures are the reason to come back. If their month does not balance it
 * says so here too — softening it in the email and then showing the shortfall
 * on the dashboard would be the worst of both.
 *
 * Queued: registration should not wait on SMTP.
 */
class WelcomeAboard extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Budget $budget) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $b    = $this->budget;
        $mail = (new MailMessage)
            ->subject('Your first MoneyPilot budget is ready')
            ->greeting('Welcome, ' . explode(' ', $notifiable->name)[0] . '.')
            ->line('Your budget is built — not from a percentage rule, but from what food and '
                 . 'transport actually cost in your area this month.');

        if ($b->feasible) {
            $mail->line('**' . Money::fmt($b->income) . '** expected this month. After rent and '
                      . 'commitments, essentials priced locally, and ' . Money::fmt($b->goal_monthly)
                      . ' set aside for your goals, the rest is yours to spend day to day.');
        } else {
            $mail->line('**Your month does not currently balance.** Your commitments, the local '
                      . 'cost of essentials and your savings target come to '
                      . Money::fmt($b->shortfall) . ' more than you expect to receive.')
                 ->line('We have not shrunk your goal to make the numbers fit. Open your budget '
                      . 'and you will find costed options for closing the gap, hardest choice last.');
        }

        return $mail
            ->action(
                $b->feasible ? 'Open my budget' : 'See what would close the gap',
                url(route('app.budget', absolute: false)),
            )
            ->line('Every figure can be opened to see exactly how it was worked out.')
            ->line('Budgeting support only — not financial, investment or tax advice.')
            ->salutation('— MoneyPilot, Lagos');
    }
}
