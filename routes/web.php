<?php

// `App` here is the App\Livewire\App namespace, which shadows the root one —
// so anything outside it is imported explicitly rather than written inline.
use App\Livewire\App;
use App\Livewire\Auth;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\Register;
use App\Livewire\Console;
use App\Livewire\Home;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Support\Facades\Auth as AuthFacade;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| MoneyPilot
|--------------------------------------------------------------------------
|
| Three surfaces, one sign-in:
|
|   /            the public homepage
|   /app/*       the member's co-pilot        (role: member)
|   /console/*   the operations console       (role: ops, owner)
|
| The role is decided by the account, never by a dropdown the visitor picks
| from — the prototype's "choose your side" screen asked a question only the
| system cared about.
|
*/

Route::get('/', Home::class)->name('home');

/* --- authentication ------------------------------------------------------ */

Route::middleware('guest')->group(function () {
    Route::get('/login', Login::class)->name('login');
    Route::get('/register', Register::class)->name('register');
    Route::get('/forgot-password', Auth\ForgotPassword::class)->name('password.request');
    Route::get('/reset-password/{token}', Auth\ResetPassword::class)->name('password.reset');
});

Route::post('/logout', function () {
    AuthFacade::logout();
    session()->invalidate();
    session()->regenerateToken();

    return redirect()->route('home');
})->name('logout');

/* --- email verification --------------------------------------------------
   Reachable, never forced. No route in this file carries the `verified`
   middleware: a budget is useful before an email round-trip completes, and
   locking somebody out of what they just built is how you lose them at the
   door. Verification exists so we can reach a member, not to gate them. */

Route::middleware('auth')->group(function () {
    Route::get('/verify-email', Auth\VerifyNotice::class)->name('verification.notice');

    Route::get('/verify-email/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route($request->user()->homeRoute())
            ->with('status', 'Your email is confirmed.');
    })->middleware('signed')->name('verification.verify');
});

/* --- the member's app ----------------------------------------------------
   Everything a person sees about their own money. `verified` is deliberately
   not required: a budget is useful before an email round-trip completes. */

Route::middleware(['auth'])->prefix('app')->name('app.')->group(function () {
    Route::get('/',          App\Dashboard::class)->name('dashboard');
    Route::get('/budget',    App\BudgetScreen::class)->name('budget');
    Route::get('/spend',     App\Spend::class)->name('spend');
    Route::get('/prices',    App\Prices::class)->name('prices');
    Route::get('/prices/{item:slug}', App\PriceDetail::class)->name('price');
    Route::get('/plan',      App\Plan::class)->name('plan');
    Route::get('/goals',     App\GoalsScreen::class)->name('goals');
    Route::get('/health',    App\HealthCheck::class)->name('health');
    Route::get('/coach',     App\Coach::class)->name('coach');
    Route::get('/profile',   App\ProfileScreen::class)->name('profile');
});

/* --- the operations console ---------------------------------------------
   Gated by a policy on the role, not by hiding the link. */

Route::middleware(['auth', 'can:use-console'])->prefix('console')->name('console.')->group(function () {
    Route::get('/',             Console\Overview::class)->name('overview');
    Route::get('/moderation',   Console\Moderation::class)->name('moderation');
    Route::get('/coverage',     Console\Coverage::class)->name('coverage');
    Route::get('/contributors', Console\Contributors::class)->name('contributors');
    Route::get('/index',        Console\PriceIndex::class)->name('index');
    Route::get('/members',      Console\Members::class)->name('members');

    // The owner's view of whether the product is working at all.
    Route::get('/business', Console\Business::class)
        ->middleware('can:view-business')
        ->name('business');
});
