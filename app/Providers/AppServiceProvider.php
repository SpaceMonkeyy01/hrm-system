<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Catch lazy-loading (N+1) and typos in attribute names while developing.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Permissions. Routes use them as middleware: ->middleware('can:edit-hr')
        Gate::define('edit-hr', fn (User $user) => $user->canEdit());   // admin, hr
        Gate::define('admin', fn (User $user) => $user->isAdmin());      // admin only
    }
}
