<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * État de la dernière synchro Zabbix (SCRUM-36) : les pages lisent la base, pas Zabbix ;
 * ce marqueur leur permet d'afficher « Monitoring service temporarily unavailable » (§47)
 * quand les données ne sont plus mises à jour.
 */
class ZabbixSyncHealth
{
    private const SUCCEEDED = 'zabbix.sync.succeeded_at';

    private const FAILED = 'zabbix.sync.failed_at';

    public static function recordSuccess(): void
    {
        Cache::forever(self::SUCCEEDED, now()->timestamp);
        Cache::forget(self::FAILED);
    }

    public static function recordFailure(): void
    {
        // On garde l'heure de la première panne : elle date le début de l'indisponibilité.
        Cache::add(self::FAILED, now()->timestamp);
    }

    /** Vrai si la dernière synchro a échoué. */
    public static function isFailing(): bool
    {
        return Cache::has(self::FAILED);
    }

    public static function failingSince(): ?Carbon
    {
        $timestamp = Cache::get(self::FAILED);

        return $timestamp ? Carbon::createFromTimestamp($timestamp) : null;
    }
}
