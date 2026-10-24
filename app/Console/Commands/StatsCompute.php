<?php

namespace App\Console\Commands;

use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use App\Services\StatisticsService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Statistiques journalières des targets (SCRUM-51), chaque heure : aujourd'hui et hier (hier se termine à minuit, ses
 * derniers incidents se résolvent parfois après). --days=30 recalcule les 30 derniers jours (après une installation).
 * Relancer remplace les lignes existantes, sans doublon.
 */
class StatsCompute extends Command
{
    protected $signature = 'stats:compute {--days=2 : nombre de jours, aujourd\'hui compris}';

    protected $description = 'Calcule les statistiques journalières des targets (disponibilité, temps de réponse, incidents, MTTR)';

    public function handle(StatisticsService $statistics): int
    {
        $days = max(1, min(31, (int) $this->option('days')));
        $today = CarbonImmutable::now(config('app.display_timezone'));
        $rows = 0;
        $failed = 0;

        foreach (MonitoringTarget::orderBy('id')->get() as $target) {
            for ($i = $days - 1; $i >= 0; $i--) {
                try {
                    $rows += $statistics->computeDay($target, $today->subDays($i)) !== null ? 1 : 0;
                } catch (ZabbixException $e) {
                    $failed++;
                    $this->components->error("{$target->name} (#{$target->id}), {$today->subDays($i)->toDateString()} : {$e->getMessage()}");
                }
            }
        }

        $this->components->info("{$rows} daily statistic(s) computed, {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
