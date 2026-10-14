<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Zk\AttendanceMachine;
use App\Support\Zk\ZkClient;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One connection per device (Attendance > Devices): MachineSyncer::connection() passes the device.
        $this->app->bind(AttendanceMachine::class, fn ($app, array $p) => new ZkClient(
            $p['device']->host, $p['device']->port, (int) $p['device']->comm_key, $p['device']->timeout_seconds,
        ));
    }

    public function boot(): void
    {
        // Catch lazy-loading (N+1) and typos in attribute names while developing.
        Model::shouldBeStrict(! $this->app->isProduction());

        // Permissions. Routes use them as middleware: ->middleware('can:edit-hr')
        Gate::define('edit-hr', fn (User $user) => $user->canEdit());   // admin, hr
        Gate::define('admin', fn (User $user) => $user->isAdmin());      // admin only
        Gate::define('view-hr', fn (User $user) => $user->canViewHr());  // admin, hr, viewer: every HR page
        Gate::define('self-service', fn (User $user) => $user->hasSelfService());   // a login linked to an employee: My page
    }
}
