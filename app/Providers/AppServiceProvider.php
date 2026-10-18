<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\ProvisionerRegistry;
use App\Services\ZabbixService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProvisionerRegistry::class);

        $this->app->singleton(ZabbixService::class, fn () => new ZabbixService(
            url: config('services.zabbix.url'),
            token: config('services.zabbix.token'),
            logger: Log::channel('zabbix'),
            timeout: config('services.zabbix.timeout'),
            retries: config('services.zabbix.retries'),
            retryDelayMs: config('services.zabbix.retry_delay'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();

        // Chaque permission est aussi une ability : @can('user.create', $team), Gate::authorize('configuration.manage', $team)
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user, Team $team) => $user->hasPermission($permission, $team));
        }

        // Date stockée en UTC → heure affichée à l'utilisateur
        Carbon::macro('toDisplay', fn () => $this->copy()->setTimezone(config('app.display_timezone')));
    }
}
