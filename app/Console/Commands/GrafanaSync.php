<?php

namespace App\Console\Commands;

use App\Exceptions\GrafanaException;
use App\Exceptions\Zabbix\ZabbixException;
use App\Models\Team;
use App\Models\User;
use App\Services\GrafanaAccessService;
use Illuminate\Console\Command;

/**
 * Synchro de l'accès à Grafana (SCRUM-48), chaque heure : organisation, datasource, dashboard et utilisateur Zabbix
 * de chaque team déjà ouverte dans Grafana ; organisations de chaque compte Grafana (Viewer de ses teams, retiré des
 * autres). Rattrape un retrait de team non répercuté (Grafana indisponible à ce moment) et met à jour les dashboards.
 */
class GrafanaSync extends Command
{
    protected $signature = 'grafana:sync';

    protected $description = 'Synchronise les organisations, datasources, dashboards et comptes Grafana des teams';

    public function handle(GrafanaAccessService $access): int
    {
        $failed = 0;

        foreach (Team::whereNotNull('grafana_org_id')->orderBy('id')->get() as $team) {
            try {
                $access->syncTeam($team);
            } catch (GrafanaException|ZabbixException $e) {
                $failed++;
                $this->components->error("Team {$team->name} (#{$team->id}) : {$e->getMessage()}");
            }
        }

        foreach (User::whereNotNull('grafana_user_id')->orderBy('id')->get() as $user) {
            try {
                $access->syncUser($user);
            } catch (GrafanaException|ZabbixException $e) {
                $failed++;
                $this->components->error("User #{$user->id} : {$e->getMessage()}");
            }
        }

        $failed === 0
            ? $this->components->info('Grafana access synced.')
            : $this->components->warn("{$failed} sync(s) failed, see above.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
