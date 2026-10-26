<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Enums\PlatformPermission;
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

        // Plateforme (SCRUM-81) : @can('platform.users.manage'), Gate::authorize('isSuperAdmin'), sans team
        foreach (PlatformPermission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user) => $user->hasPlatformPermission($permission));
        }
        Gate::define('isSuperAdmin', fn (User $user) => $user->isSuperAdmin());

        // Inviter dans une team (SCRUM-83) : un Admin de la team, ou un Super Admin vers n'importe quelle team
        Gate::define('team.invite', fn (User $user, Team $team) => $user->hasPermission(Permission::UserCreate, $team)
            || $user->hasPlatformPermission(PlatformPermission::UsersManage));

        // Date stockée en UTC → heure affichée à l'utilisateur
        Carbon::macro('toDisplay', fn () => $this->copy()->setTimezone(config('app.display_timezone')));
    }
}
