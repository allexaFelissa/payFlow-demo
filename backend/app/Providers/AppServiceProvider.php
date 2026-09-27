<?php

namespace App\Providers;

use App\Database\Connectors\NeonPostgresConnector;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind('db.connector.pgsql', fn () => new NeonPostgresConnector);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::define('manage-loans', fn (User $user) => $user->isAdministrator());
        Gate::define('manage-payroll', fn (User $user) => $user->isAdministrator());
    }
}
