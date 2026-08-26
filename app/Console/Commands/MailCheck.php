<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use App\Notifications\WelcomeAboard;
use App\Services\BudgetService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Send every MoneyPilot email, for real, to a Mailtrap inbox.
 *
 * `Notification::fake()` proves the code decides to send. It does not prove the
 * SMTP credentials are right, that the template renders, or that the naira sign
 * survives the transport — and those are exactly the things that only fail in
 * front of an audience.
 *
 *   php artisan mail:check                    (to the first demo member)
 *   php artisan mail:check you@example.com
 */
class MailCheck extends Command
{
    protected $signature = 'mail:check {to? : Override the recipient address}';

    protected $description = 'Send one of each MoneyPilot email through the configured mailer';

    public function handle(BudgetService $budgets): int
    {
        /*
         * Transport-aware, because there are now two.
         *
         * This printed the SMTP host and refused on an empty MAIL_USERNAME
         * whatever the mailer was — so the moment mail was switched to Resend
         * for production, the one command that exists to verify mail told you
         * to go and find your Mailtrap credentials. Wrong advice from a
         * pre-flight check is worse than no check.
         */
        $mailer = config('mail.default');

        $this->line('');
        $this->line('  mailer   ' . $mailer);

        if ($mailer === 'resend') {
            $key = (string) config('services.resend.key', env('RESEND_API_KEY', ''));

            // A fingerprint, never the key.
            $this->line('  key      ' . ($key === ''
                ? '(empty)'
                : substr($key, 0, 3) . '…' . substr($key, -4) . '  (' . strlen($key) . ' chars)'));
            $this->line('  from     ' . config('mail.from.address'));

            if ($key === '') {
                $this->line('');
                $this->error('  RESEND_API_KEY is empty — set it in .env first.');
                $this->line('  resend.com → API Keys. The sending domain must be verified,');
                $this->line('  and MAIL_FROM_ADDRESS must be on it or every send is rejected.');

                return self::FAILURE;
            }
        } else {
            $this->line('  host     ' . config('mail.mailers.smtp.host') . ':' . config('mail.mailers.smtp.port'));
            $this->line('  from     ' . config('mail.from.address'));

            if (blank(config('mail.mailers.smtp.username'))) {
                $this->line('');
                $this->error('  MAIL_USERNAME is empty — put your Mailtrap credentials in .env first.');
                $this->line('  Mailtrap → Email Testing → your inbox → Integrations → Laravel 9+');

                return self::FAILURE;
            }
        }

        $user = User::where('email', 'chidi@student.unilag.ng')->first()
            ?? User::where('role', 'member')->first();

        if (! $user) {
            $this->error('  No member to send as. Run: php artisan migrate:fresh --seed');

            return self::FAILURE;
        }

        // Mailtrap catches everything, so the address is only a label — but an
        // override makes it obvious which run an email belongs to.
        if ($to = $this->argument('to')) {
            $user = clone $user;
            $user->email = $to;
        }

        $this->line('  to       ' . $user->email);
        $this->line('');

        $budget = $budgets->current($user);

        $sends = [
            'verification link'  => fn () => $user->notify(new VerifyEmail),
            'password reset'     => fn () => $user->notify(new ResetPassword('a-demonstration-token')),
            'first budget'       => fn () => $user->notifyNow(new WelcomeAboard($budget)),
        ];

        $failed = 0;

        foreach ($sends as $label => $send) {
            try {
                $send();
                $this->info(sprintf('  sent   %s', $label));
            } catch (\Throwable $e) {
                $failed++;
                $this->error(sprintf('  FAILED %-18s %s', $label, $e->getMessage()));
            }
        }

        $this->line('');

        if ($failed) {
            $this->error("  {$failed} of " . count($sends) . ' failed.');

            return self::FAILURE;
        }

        $this->info($mailer === 'resend'
            ? '  All three were accepted by Resend. Check the real inbox: ₦ renders, links resolve.'
            : '  All three are in your Mailtrap inbox. Check that ₦ renders and the links resolve.');
        $this->line('');

        return self::SUCCESS;
    }
}
