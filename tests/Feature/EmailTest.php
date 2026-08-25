<?php

namespace Tests\Feature;

use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Register;
use App\Livewire\Auth\ResetPassword as ResetScreen;
use App\Livewire\Auth\VerifyNotice;
use App\Models\User;
use App\Notifications\ResetPassword;
use App\Notifications\VerifyEmail;
use App\Notifications\WelcomeAboard;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\SeededTestCase;

/**
 * Everything that leaves the building by email.
 *
 * Two properties get most of the attention here, because both are the kind of
 * thing that is easy to get wrong and expensive to get wrong:
 *
 *   - The forgot-password form must answer identically whether or not the
 *     address exists, or it becomes a free membership oracle.
 *   - Verification must never be a gate. A budget is useful before an email
 *     round-trip completes.
 */
class EmailTest extends SeededTestCase
{
    private function member(): User
    {
        return User::where('email', 'chidi@student.unilag.ng')->firstOrFail();
    }

    private function fillRegistration($test): void
    {
        $test->set('locationId', \App\Models\Location::value('id'))
             ->set('householdSize', 2)->set('dependents', 1)
             ->call('next')
             ->set('incomeAmount', '150,000')->set('incomeReliability', 'certain')
             ->set('rentAmount', '30,000')
             ->call('next');
    }

    /* ---------------- registration ---------------- */

    public function test_registering_sends_a_verification_link_and_a_first_budget_email(): void
    {
        Notification::fake();

        $test = Livewire::test(Register::class);
        $this->fillRegistration($test);

        $test->set('name', 'Ngozi Umeh')
             ->set('email', 'ngozi.umeh@example.ng')
             ->set('password', 'correct-horse-battery')
             ->set('passwordConfirmation', 'correct-horse-battery')
             ->call('register')
             ->assertHasNoErrors();

        $user = User::where('email', 'ngozi.umeh@example.ng')->firstOrFail();

        Notification::assertSentTo($user, VerifyEmail::class);
        Notification::assertSentTo($user, WelcomeAboard::class);
    }

    /** The welcome email carries figures, not a greeting. */
    public function test_the_first_email_states_the_members_actual_position(): void
    {
        Notification::fake();

        $test = Livewire::test(Register::class);
        $this->fillRegistration($test);
        $test->set('name', 'Musa Bello')->set('email', 'musa.bello@example.ng')
             ->set('password', 'correct-horse-battery')
             ->set('passwordConfirmation', 'correct-horse-battery')
             ->call('register');

        $user = User::where('email', 'musa.bello@example.ng')->firstOrFail();

        Notification::assertSentTo($user, WelcomeAboard::class, function ($notification) use ($user) {
            $mail = $notification->toMail($user);
            // JSON_UNESCAPED_UNICODE, or the naira sign arrives as \u20a6.
            $body = json_encode($mail->toArray(), JSON_UNESCAPED_UNICODE);

            $this->assertStringContainsString('₦', $body, 'an email with no figure is a greeting');
            $this->assertStringContainsString('not financial, investment or tax advice', $body);

            return true;
        });
    }

    public function test_the_welcome_email_is_queued_so_smtp_never_blocks_sign_up(): void
    {
        $this->assertInstanceOf(
            \Illuminate\Contracts\Queue\ShouldQueue::class,
            new WelcomeAboard(app(\App\Services\BudgetService::class)->current($this->member())),
        );
    }

    /* ---------------- verification ---------------- */

    public function test_the_verification_link_confirms_the_address(): void
    {
        $user = User::factory()->create([
            'role'              => 'member',
            'email_verified_at' => null,
        ]);

        $this->assertFalse($user->hasVerifiedEmail());

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id'   => $user->id,
            'hash' => sha1($user->email),
        ]);

        $this->actingAs($user)->get($url)->assertRedirect(route('app.dashboard'));

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_an_unsigned_or_tampered_verification_link_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'member', 'email_verified_at' => null]);

        // No signature at all.
        $this->actingAs($user)
            ->get(route('verification.verify', ['id' => $user->id, 'hash' => sha1($user->email)]))
            ->assertForbidden();

        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_one_members_link_cannot_verify_another_members_address(): void
    {
        $a = User::factory()->create(['role' => 'member', 'email_verified_at' => null]);
        $b = User::factory()->create(['role' => 'member', 'email_verified_at' => null]);

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), [
            'id'   => $b->id,
            'hash' => sha1($b->email),
        ]);

        $this->actingAs($a)->get($url)->assertForbidden();

        $this->assertFalse($b->fresh()->hasVerifiedEmail());
    }

    /**
     * The deliberate choice: verification is reachable, never forced.
     */
    public function test_an_unverified_member_can_still_use_every_screen(): void
    {
        $user = $this->member();
        $user->forceFill(['email_verified_at' => null])->save();

        foreach (['/app', '/app/budget', '/app/spend', '/app/prices', '/app/coach'] as $uri) {
            $this->actingAs($user)->get($uri)->assertOk();
        }
    }

    public function test_resending_verification_is_throttled(): void
    {
        Notification::fake();

        $user = $this->member();
        $user->forceFill(['email_verified_at' => null])->save();

        $c = Livewire::actingAs($user)->test(VerifyNotice::class);

        for ($i = 0; $i < 5; $i++) {
            $c->call('resend');
        }

        $c->assertSet('flash', fn ($v) => str_contains($v, 'sent a few already'));
    }

    /* ---------------- password reset ---------------- */

    public function test_asking_for_a_reset_sends_a_link(): void
    {
        Notification::fake();

        Livewire::test(ForgotPassword::class)
            ->set('email', 'chidi@student.unilag.ng')
            ->call('send')
            ->assertSet('sent', true)
            ->assertHasNoErrors();

        Notification::assertSentTo($this->member(), ResetPassword::class);
    }

    /**
     * The property that matters: an unknown address gets the same answer as a
     * known one. Otherwise this form tells a stranger who has an account.
     */
    public function test_an_unknown_address_is_answered_identically(): void
    {
        Notification::fake();

        Livewire::test(ForgotPassword::class)
            ->set('email', 'nobody-at-all@example.ng')
            ->call('send')
            ->assertSet('sent', true)
            ->assertHasNoErrors();

        Notification::assertNothingSent();
    }

    public function test_reset_requests_are_throttled(): void
    {
        Notification::fake();

        $c = Livewire::test(ForgotPassword::class)->set('email', 'chidi@student.unilag.ng');

        $c->call('send')->call('send')->call('send')->call('send');

        $c->assertHasErrors('email');
    }

    public function test_a_valid_token_changes_the_password_and_signs_the_member_in(): void
    {
        $user  = $this->member();
        $token = Password::createToken($user);

        Livewire::test(ResetScreen::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'a-brand-new-password')
            ->set('passwordConfirmation', 'a-brand-new-password')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('app.dashboard'));

        $this->assertTrue(Hash::check('a-brand-new-password', $user->fresh()->password));
        $this->assertAuthenticatedAs($user);
    }

    /** A reset must not leave an intruder's remembered session alive. */
    public function test_resetting_rotates_the_remember_token(): void
    {
        $user = $this->member();
        $user->forceFill(['remember_token' => 'an-old-remembered-device'])->save();

        $token = Password::createToken($user);

        Livewire::test(ResetScreen::class, ['token' => $token])
            ->set('email', $user->email)
            ->set('password', 'another-new-password')
            ->set('passwordConfirmation', 'another-new-password')
            ->call('save');

        $this->assertNotSame('an-old-remembered-device', $user->fresh()->remember_token);
    }

    public function test_a_stale_token_is_refused_with_a_useful_message(): void
    {
        Livewire::test(ResetScreen::class, ['token' => 'not-a-real-token'])
            ->set('email', 'chidi@student.unilag.ng')
            ->set('password', 'does-not-matter-here')
            ->set('passwordConfirmation', 'does-not-matter-here')
            ->call('save')
            ->assertHasErrors('email');
    }

    public function test_mismatched_passwords_are_refused(): void
    {
        $token = Password::createToken($this->member());

        Livewire::test(ResetScreen::class, ['token' => $token])
            ->set('email', 'chidi@student.unilag.ng')
            ->set('password', 'one-password-here')
            ->set('passwordConfirmation', 'a-different-one')
            ->call('save')
            ->assertHasErrors('password');
    }

    /* ---------------- the emails themselves ---------------- */

    public function test_the_reset_email_states_what_moneypilot_will_never_ask_for(): void
    {
        $user = $this->member();
        $mail = (new ResetPassword('a-token'))->toMail($user);
        $body = json_encode($mail->toArray());

        $this->assertStringContainsString('never ask for your bank login', $body,
            'a reset email is the most effective phishing template there is');
        $this->assertStringContainsString('MoneyPilot', $mail->subject);
    }

    public function test_the_verification_email_says_it_is_not_a_lock(): void
    {
        $mail = (new VerifyEmail)->toMail($this->member());
        $body = json_encode($mail->toArray());

        $this->assertStringContainsString('not a lock on your account', $body);
    }

    /* ---------------- configuration ---------------- */

    public function test_the_registered_event_is_wired_to_send_verification(): void
    {
        Notification::fake();

        $user = User::factory()->create(['role' => 'member', 'email_verified_at' => null]);

        event(new Registered($user));

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_the_user_model_requires_verification_and_uses_our_own_notifications(): void
    {
        $this->assertInstanceOf(
            \Illuminate\Contracts\Auth\MustVerifyEmail::class,
            $this->member(),
        );

        Notification::fake();

        $this->member()->sendPasswordResetNotification('tok');
        Notification::assertSentTo($this->member(), ResetPassword::class);
    }
}
