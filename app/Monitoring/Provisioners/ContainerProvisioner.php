<?php

namespace App\Monitoring\Provisioners;

use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use App\Monitoring\ZabbixProvisioner;
use App\Rules\HostOrIp;

/**
 * Conteneur Docker (SCRUM-70, cahier des charges §5.4) : lu par l'agent Zabbix 2 de son hôte Docker, plugin Docker
 * (settings.address / settings.port, par défaut l'agent de la plateforme, SCRUM-67 ; settings.container = nom).
 *
 * Deux items d'agent, non conservés (history 0) : docker.container_info[nom] et docker.container_stats[nom]. Des items
 * dépendants en tirent l'état, les redémarrages, l'uptime, le CPU, la mémoire et le réseau (JSONPath / JavaScript).
 * Triggers, chacun dans sa chaîne : arrêté ou introuvable (CRITICAL), redémarrages en boucle (HIGH),
 * mémoire au-dessus du seuil en Mo (WARNING).
 */
class ContainerProvisioner extends ZabbixProvisioner
{
    /** Nom de conteneur Docker : il entre dans la clé des items, aucun autre caractère n'est accepté. */
    public const NAME_PATTERN = '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/';

    public const DEFAULT_MEMORY_WARNING_MB = 512;

    /** Redémarrages en boucle : plus de RESTART_LOOP_COUNT redémarrages en RESTART_LOOP_WINDOW. */
    public const RESTART_LOOP_COUNT = 2;

    public const RESTART_LOOP_WINDOW = '10m';

    /** Items dépendants : nom de la métrique → clé */
    public const KEYS = [
        'running' => 'container.running',
        'state' => 'container.status',
        'restarts' => 'container.restarts',
        'uptime' => 'container.uptime',
        'cpu' => 'container.cpu',
        'memory' => 'container.memory',
        'net_in' => 'container.net.in',
        'net_out' => 'container.net.out',
    ];

    private const ITEM_ZABBIX_AGENT = 0;

    private const ITEM_DEPENDENT = 18;

    private const VALUE_FLOAT = 0;

    private const VALUE_CHAR = 1;

    private const VALUE_UNSIGNED = 3;

    private const VALUE_TEXT = 4;

    private const PREPROCESSING_BOOL_TO_DECIMAL = 6;

    private const PREPROCESSING_CHANGE_PER_SECOND = 10;

    private const PREPROCESSING_JSONPATH = 12;

    private const PREPROCESSING_JAVASCRIPT = 21;

    private const MB = 1048576;

    public function rules(): array
    {
        return [
            'settings.container' => ['required', 'string', 'max:128', 'regex:'.self::NAME_PATTERN],
            'settings.address' => ['required', 'string', 'max:255', new HostOrIp],
            'settings.port' => ['required', 'integer', 'between:1,65535'],
            'settings.memory_warning' => ['required', 'integer', 'between:1,1048576'],
        ];
    }

    public function messages(): array
    {
        return [
            'settings.container.regex' => 'The container name may contain letters, digits, "_", "." and "-" only.',
        ];
    }

    public function attributes(): array
    {
        return [
            'settings.container' => 'container name',
            'settings.address' => 'Docker host',
            'settings.port' => 'agent port',
            'settings.memory_warning' => 'memory threshold',
        ];
    }

    public function hostSettings(MonitoringTarget $target): array
    {
        return $this->agentInterface($target->setting('address'), (int) $target->setting('port'));
    }

    public function create(MonitoringTarget $target, string $hostId): void
    {
        $interfaceId = $this->agentInterfaceOf($hostId)['interfaceid'];

        [$infoId] = $this->zabbix->createItems($this->masterItem($target, $hostId, $interfaceId, 'info'));
        [$statsId] = $this->zabbix->createItems($this->masterItem($target, $hostId, $interfaceId, 'stats'));
        $this->zabbix->createItems($this->dependentItems($hostId, $infoId, $statsId));

        $this->createChainedTriggers($target);
    }

    public function update(MonitoringTarget $target): void
    {
        $hostId = $target->zabbix_host_id;

        $this->updateAgentInterface($hostId, $target->setting('address'), (int) $target->setting('port'));

        // Autre conteneur ou autre intervalle : on modifie les 2 items maîtres, leurs items dépendants suivent
        $masters = collect($this->zabbix->getItems([
            'output' => ['itemid', 'key_'],
            'hostids' => [$hostId],
            'search' => ['key_' => 'docker.container_'],
            'startSearch' => true,
        ]))->keyBy(fn (array $item) => str_starts_with($item['key_'], 'docker.container_info[') ? 'info' : 'stats');

        if ($masters->count() !== 2) {
            throw new ZabbixException('Docker items of the container not found in Zabbix.', 'item.get');
        }

        $this->zabbix->updateItems($masters->map(fn (array $item, string $kind) => [
            'itemid' => $item['itemid'],
            'key_' => $this->masterKey($target, $kind),
            'delay' => "{$target->check_interval}s",
        ])->values()->all());

        $this->updateTriggers($target);
    }

    public function statusItemKeys(): array
    {
        return ['availability' => self::KEYS['running'], 'response_time' => null];
    }

    public function metricItemKeys(): array
    {
        return array_diff_key(self::KEYS, ['running' => true]);
    }

    public function threshold(MonitoringTarget $target, string $level): int|float|null
    {
        return match ($level) {
            'restart_loop' => self::RESTART_LOOP_COUNT,
            'memory_warning' => $target->setting('memory_warning'),
            default => null,
        };
    }

    /** Graphiques (SCRUM-72) : CPU en %, mémoire en Mo avec son seuil */
    protected function metricSeries(): array
    {
        return [
            'cpu' => ['label' => 'CPU', 'key' => self::KEYS['cpu'], 'unit' => '%', 'scale' => 1, 'value_type' => self::VALUE_TYPE_FLOAT],
            'memory' => ['label' => 'Memory', 'key' => self::KEYS['memory'], 'unit' => 'MB', 'scale' => 1 / self::MB, 'value_type' => self::VALUE_TYPE_UNSIGNED],
        ];
    }

    /** Alerte mémoire d'un conteneur : métrique container_memory, série memory (Mo, comme la valeur de l'alerte) */
    public function incidentSeries(string $metric): ?string
    {
        return $metric === 'container_memory' ? 'memory' : parent::incidentSeries($metric);
    }

    public function chartThresholds(MonitoringTarget $target, string $series): array
    {
        return $series === 'memory'
            ? ['warning' => $target->setting('memory_warning'), 'critical' => null]
            : parent::chartThresholds($target, $series);
    }

    public function checkSummary(MonitoringTarget $target): array
    {
        return [
            sprintf('Container "%s" read by the Zabbix agent (Docker plugin) at %s:%d',
                $target->setting('container'), $target->setting('address'), $target->setting('port')),
            'Critical when the container is stopped or cannot be found',
            sprintf('High when it restarts more than %d times in 10 minutes', self::RESTART_LOOP_COUNT),
            sprintf('Warning when its memory is above %s MB (5-minute average)', number_format((int) $target->setting('memory_warning'))),
        ];
    }

    protected function triggerDefinitions(MonitoringTarget $target): array
    {
        $item = fn (string $metric) => '/'.$target->zabbixHostName().'/'.self::KEYS[$metric];
        // Pas de nouvelle valeur pendant 3 intervalles (au moins 3 min) : conteneur supprimé, agent injoignable…
        $noData = max(180, 3 * $target->check_interval).'s';
        $memory = (int) $target->setting('memory_warning');

        return [
            'stopped' => [
                'description' => "{$target->name} container is stopped",
                'expression' => "last({$item('running')})=0 or nodata({$item('running')},$noData)=1",
                'priority' => self::PRIORITY_DISASTER,
                'tags' => $this->triggerTags($target, 'availability', 'stopped'),
                'chain' => 'availability',
            ],
            'restart_loop' => [
                'description' => sprintf('%s container restarts in a loop (more than %d restarts in 10 minutes)', $target->name, self::RESTART_LOOP_COUNT),
                'expression' => "last({$item('restarts')})-min({$item('restarts')},".self::RESTART_LOOP_WINDOW.')>'.self::RESTART_LOOP_COUNT,
                'priority' => self::PRIORITY_HIGH,
                'tags' => $this->triggerTags($target, 'restarts', 'restart_loop'),
                'chain' => 'restarts',
            ],
            'memory_warning' => [
                'description' => "{$target->name} container memory > {$memory} MB",
                'expression' => "avg({$item('memory')},5m)>".($memory * self::MB),
                'priority' => self::PRIORITY_WARNING,
                'tags' => $this->triggerTags($target, 'container_memory', 'memory_warning'),
                'chain' => 'memory',
            ],
        ];
    }

    private function masterKey(MonitoringTarget $target, string $kind): string
    {
        return sprintf('docker.container_%s[%s]', $kind, $target->setting('container'));
    }

    /** Item d'agent qui lit le JSON de Docker ; non conservé, il ne sert qu'à alimenter les items dépendants. */
    private function masterItem(MonitoringTarget $target, string $hostId, string $interfaceId, string $kind): array
    {
        return [
            'hostid' => $hostId,
            'interfaceid' => $interfaceId,
            'name' => $kind === 'info' ? 'Container: information' : 'Container: statistics',
            'key_' => $this->masterKey($target, $kind),
            'type' => self::ITEM_ZABBIX_AGENT,
            'value_type' => self::VALUE_TEXT,
            'delay' => "{$target->check_interval}s",
            'history' => '0',
        ];
    }

    private function dependentItems(string $hostId, string $infoId, string $statsId): array
    {
        $jsonPath = fn (string $path) => ['type' => self::PREPROCESSING_JSONPATH, 'params' => $path, 'error_handler' => 0, 'error_handler_params' => ''];
        $javascript = fn (string $code) => ['type' => self::PREPROCESSING_JAVASCRIPT, 'params' => $code, 'error_handler' => 0, 'error_handler_params' => ''];
        $perSecond = ['type' => self::PREPROCESSING_CHANGE_PER_SECOND, 'params' => '', 'error_handler' => 0, 'error_handler_params' => ''];
        $sumNetworks = fn (string $field) => "var n = JSON.parse(value).networks || {}, s = 0; for (var k in n) { s += n[k].$field || 0; } return s;";

        $item = fn (string $metric, string $name, string $master, int $valueType, array $preprocessing, string $units = '') => [
            'hostid' => $hostId,
            'name' => "Container: $name",
            'key_' => self::KEYS[$metric],
            'type' => self::ITEM_DEPENDENT,
            'master_itemid' => $master,
            'value_type' => $valueType,
            'units' => $units,
            'preprocessing' => $preprocessing,
        ];

        return [
            $item('running', 'running', $infoId, self::VALUE_UNSIGNED, [
                $jsonPath('$.State.Running'),
                ['type' => self::PREPROCESSING_BOOL_TO_DECIMAL, 'params' => '', 'error_handler' => 0, 'error_handler_params' => ''],
            ]),
            $item('state', 'state', $infoId, self::VALUE_CHAR, [$jsonPath('$.State.Status')]),
            $item('restarts', 'restarts', $infoId, self::VALUE_UNSIGNED, [$jsonPath('$.RestartCount')]),
            $item('uptime', 'uptime', $infoId, self::VALUE_UNSIGNED, [
                $javascript('var s = JSON.parse(value).State; return s.Running ? Math.max(0, Math.floor(Date.now() / 1000) - s.StartedAt) : 0;'),
            ], 'uptime'),
            $item('cpu', 'CPU usage', $statsId, self::VALUE_FLOAT, [$jsonPath('$.cpu_stats.cpu_usage.percent_usage')], '%'),
            $item('memory', 'memory usage', $statsId, self::VALUE_UNSIGNED, [$jsonPath('$.memory_stats.usage')], 'B'),
            $item('net_in', 'network in', $statsId, self::VALUE_FLOAT, [$javascript($sumNetworks('rx_bytes')), $perSecond], 'Bps'),
            $item('net_out', 'network out', $statsId, self::VALUE_FLOAT, [$javascript($sumNetworks('tx_bytes')), $perSecond], 'Bps'),
        ];
    }
}
