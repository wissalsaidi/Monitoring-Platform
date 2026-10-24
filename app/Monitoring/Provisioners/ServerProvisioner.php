<?php

namespace App\Monitoring\Provisioners;

use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use App\Monitoring\ZabbixProvisioner;
use App\Rules\HostOrIp;
use Illuminate\Support\Facades\Log;

/**
 * Serveur Linux (SCRUM-69, cahier des charges §5.3) : l'agent Zabbix installé sur le serveur, interrogé en mode passif
 * sur adresse:port (settings.address, settings.port).
 *
 * - Template « Linux by Zabbix agent » lié au host pour la collecte (CPU, mémoire, charge, uptime, OS, systèmes de
 *   fichiers, réseau…) et les graphiques. Ses propres triggers n'ont pas nos tags : ils restent visibles dans Zabbix
 *   mais la synchro n'en fait pas des alertes (choix SCRUM-69). Ses macros de seuils reçoivent nos valeurs.
 * - Nos triggers, tagués : indisponible (pas de réponse de l'agent depuis 3 min), puis pour CPU, mémoire et disque /
 *   une chaîne critical (HIGH) ← warning (WARNING) sur la moyenne des 5 dernières minutes, seuils en % dans settings.
 * - Disque / : item à nous (vfs.fs.size[/,pused]) ; ceux du template n'existent qu'après la découverte des systèmes de fichiers.
 */
class ServerProvisioner extends ZabbixProvisioner
{
    public const DEFAULT_PORT = 10050;

    public const DEFAULT_WARNING = 80;

    public const DEFAULT_CRITICAL = 90;

    public const DISK_KEY = 'vfs.fs.size[/,pused]';

    /** Ressources à seuils : nom de la métrique (tag "metric") → libellé et item Zabbix */
    public const RESOURCES = [
        'cpu' => ['label' => 'CPU', 'key' => 'system.cpu.util'],
        'memory' => ['label' => 'Memory', 'key' => 'vm.memory.util'],
        'disk' => ['label' => 'Disk /', 'key' => self::DISK_KEY],
    ];

    /** Sans réponse de l'agent pendant ce délai, le serveur est indisponible (macro {$AGENT.TIMEOUT} du template). */
    private const UNAVAILABLE_AFTER = '3m';

    /** Les seuils sont comparés à la moyenne sur cette durée : un pic isolé ne déclenche pas d'alerte. */
    private const AVERAGE_OVER = '5m';

    /** Items du template relevés dès la création, pour afficher OS et uptime sans attendre leur intervalle (1 h). */
    private const FIRST_CHECK_KEYS = ['system.sw.os', 'system.uptime', 'system.hostname'];

    private const ITEM_ZABBIX_AGENT = 0;

    private const VALUE_FLOAT = 0;

    private ?string $templateId = null;

    public function rules(): array
    {
        $rules = [
            'settings.address' => ['required', 'string', 'max:255', new HostOrIp],
            'settings.port' => ['required', 'integer', 'between:1,65535'],
        ];

        foreach (array_keys(self::RESOURCES) as $resource) {
            $rules["settings.{$resource}_warning"] = ['required', 'integer', 'between:1,99'];
            $rules["settings.{$resource}_critical"] = ['required', 'integer', 'max:100', "gt:settings.{$resource}_warning"];
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [];

        foreach (self::RESOURCES as $resource => ['label' => $label]) {
            $messages["settings.{$resource}_critical.gt"] = "The {$label} critical threshold must be greater than the warning threshold.";
        }

        return $messages;
    }

    public function attributes(): array
    {
        $attributes = ['settings.address' => 'address', 'settings.port' => 'agent port'];

        foreach (self::RESOURCES as $resource => ['label' => $label]) {
            $attributes["settings.{$resource}_warning"] = "{$label} warning threshold";
            $attributes["settings.{$resource}_critical"] = "{$label} critical threshold";
        }

        return $attributes;
    }

    public function hostSettings(MonitoringTarget $target): array
    {
        return [
            ...$this->agentInterface($target->setting('address'), (int) $target->setting('port')),
            'templates' => [['templateid' => $this->templateId()]],
            'macros' => $this->templateMacros($target),
        ];
    }

    public function create(MonitoringTarget $target, string $hostId): void
    {
        $this->zabbix->createItems([
            'hostid' => $hostId,
            'interfaceid' => $this->agentInterfaceOf($hostId)['interfaceid'],
            'name' => 'Disk /: space used, in %',
            'key_' => self::DISK_KEY,
            'type' => self::ITEM_ZABBIX_AGENT,
            'value_type' => self::VALUE_FLOAT,
            'units' => '%',
            'delay' => "{$target->check_interval}s",
        ]);

        $this->createChainedTriggers($target);

        $this->requestFirstChecks($target, $hostId);
    }

    public function update(MonitoringTarget $target): void
    {
        $hostId = $target->zabbix_host_id;

        $this->updateAgentInterface($hostId, $target->setting('address'), (int) $target->setting('port'));

        $this->zabbix->updateHost($hostId, ['macros' => $this->templateMacros($target)]);

        $disk = $this->zabbix->getItems(['output' => ['itemid'], 'hostids' => [$hostId], 'filter' => ['key_' => [self::DISK_KEY]]])[0]
            ?? throw new ZabbixException('Disk item of the server not found in Zabbix.', 'item.get');
        $this->zabbix->updateItems([['itemid' => $disk['itemid'], 'delay' => "{$target->check_interval}s"]]);

        $this->updateTriggers($target);
    }

    public function statusItemKeys(): array
    {
        return ['availability' => 'agent.ping', 'response_time' => null];
    }

    public function metricItemKeys(): array
    {
        return [
            ...array_map(fn (array $resource) => $resource['key'], self::RESOURCES),
            'load' => 'system.cpu.load[all,avg1]',
            'uptime' => 'system.uptime',
            'os' => 'system.sw.os',
        ];
    }

    public function threshold(MonitoringTarget $target, string $level): int|float|null
    {
        [$resource, $severity] = array_pad(explode('_', $level, 2), 2, null);

        return isset(self::RESOURCES[$resource]) && in_array($severity, ['warning', 'critical'], true)
            ? $target->setting("{$resource}_{$severity}")
            : null;
    }

    /** Graphiques (SCRUM-72) : CPU, mémoire, disque /, en %, avec leurs seuils */
    public function chartSeries(): array
    {
        return array_map(fn (array $resource) => [
            'label' => $resource['label'], 'key' => $resource['key'], 'unit' => '%', 'scale' => 1, 'value_type' => self::VALUE_TYPE_FLOAT,
        ], self::RESOURCES);
    }

    public function chartThresholds(MonitoringTarget $target, string $series): array
    {
        return isset(self::RESOURCES[$series])
            ? ['warning' => $target->setting("{$series}_warning"), 'critical' => $target->setting("{$series}_critical")]
            : parent::chartThresholds($target, $series);
    }

    public function checkSummary(MonitoringTarget $target): array
    {
        $lines = [
            sprintf('Zabbix agent at %s:%d, template "%s"', $target->setting('address'), $target->setting('port'), config('monitoring.server_template')),
            'Unavailable when the agent has not answered for 3 minutes',
        ];

        foreach (self::RESOURCES as $resource => ['label' => $label]) {
            $lines[] = sprintf('%s: warning above %d %%, critical above %d %% (5-minute average)',
                $label, $target->setting("{$resource}_warning"), $target->setting("{$resource}_critical"));
        }

        return $lines;
    }

    protected function triggerDefinitions(MonitoringTarget $target): array
    {
        $host = $target->zabbixHostName();
        $definitions = [
            'unavailable' => [
                'description' => "{$target->name} is unavailable",
                'expression' => "nodata(/$host/agent.ping,".self::UNAVAILABLE_AFTER.')=1',
                'priority' => self::PRIORITY_DISASTER,
                'tags' => $this->triggerTags($target, 'availability', 'unavailable'),
            ],
        ];

        foreach (self::RESOURCES as $resource => ['label' => $label, 'key' => $key]) {
            foreach (['critical' => self::PRIORITY_HIGH, 'warning' => self::PRIORITY_WARNING] as $severity => $priority) {
                $threshold = (int) $target->setting("{$resource}_{$severity}");
                $definitions["{$resource}_{$severity}"] = [
                    'description' => "{$target->name} {$label} > {$threshold} %",
                    'expression' => "avg(/$host/$key,".self::AVERAGE_OVER.")>$threshold",
                    'priority' => $priority,
                    'tags' => $this->triggerTags($target, $resource, "{$resource}_{$severity}"),
                    'chain' => $resource,
                ];
            }
        }

        return $definitions;
    }

    /** Macros de seuils du template, alignées sur les nôtres : les écrans de Zabbix restent cohérents avec la plateforme. */
    private function templateMacros(MonitoringTarget $target): array
    {
        return [
            ['macro' => '{$CPU.UTIL.CRIT}', 'value' => (string) $target->setting('cpu_critical')],
            ['macro' => '{$MEMORY.UTIL.MAX}', 'value' => (string) $target->setting('memory_critical')],
            ['macro' => '{$VFS.FS.PUSED.MAX.WARN}', 'value' => (string) $target->setting('disk_warning')],
            ['macro' => '{$VFS.FS.PUSED.MAX.CRIT}', 'value' => (string) $target->setting('disk_critical')],
        ];
    }

    /** Id du template Linux (config monitoring.server_template), lu une fois par requête. */
    private function templateId(): string
    {
        $name = config('monitoring.server_template');

        return $this->templateId ??= $this->zabbix->getTemplates(['filter' => ['host' => [$name]]])[0]['templateid']
            ?? throw new ZabbixException("Template \"{$name}\" not found in Zabbix.", 'template.get');
    }

    /** Facultatif : sans ça, OS et uptime peuvent attendre une heure. Un échec ne bloque pas la création. */
    private function requestFirstChecks(MonitoringTarget $target, string $hostId): void
    {
        try {
            $ids = array_column($this->zabbix->getItems([
                'output' => ['itemid'],
                'hostids' => [$hostId],
                'filter' => ['key_' => [...self::FIRST_CHECK_KEYS, self::DISK_KEY]],
            ]), 'itemid');

            if ($ids !== []) {
                $this->zabbix->checkNow(...$ids);
            }
        } catch (ZabbixException $e) {
            Log::channel('zabbix')->warning('Unable to request the first checks of a server', ['target_id' => $target->id, 'error' => $e->getMessage()]);
        }
    }
}
