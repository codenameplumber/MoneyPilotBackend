<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Access is decided by the account role, server-side.
         *
         * The prototype "enforced" this by not drawing the link, which is not
         * enforcement — anyone who typed the URL was an administrator. These
         * gates run before the component is ever constructed.
         */
        Gate::define("use-console", fn ($user) => $user->canUseConsole());

        // Revenue, retention and unit economics are the owner business.
        Gate::define("view-business", fn ($user) => $user->isOwner());
    }
}
