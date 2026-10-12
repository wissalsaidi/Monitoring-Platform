<?php

namespace App\Providers;

use App\Services\ZabbixService;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
    }
}
