<?php

namespace App\Monitoring\Provisioners;

use App\Exceptions\Zabbix\ZabbixException;
use App\Models\MonitoringTarget;
use App\Monitoring\ZabbixProvisioner;
use App\Rules\HostOrIp;

/**
 * Base de données MySQL (SCRUM-71, cahier des charges §5.5) : lue par l'agent Zabbix 2 de la plateforme (plugin MySQL,
 * SCRUM-67) avec un utilisateur de supervision en lecture seule.
 *
 * Secret : le mot de passe est chiffré dans la colonne credentials (jamais dans settings, jamais affiché) et envoyé à
 * Zabbix dans la macro secrète {$MYSQL.PASSWORD} du host. Les clés d'items ne contiennent que des macros.
 *
 * Items : mysql.ping (disponibilité), mysql.get_status_variables non conservé → connexions, requêtes lentes,
 * requêtes/s, uptime ; mysql.version ; temps de réponse = connexion TCP mesurée par zabbix-server (check simple).
 * Alertes : injoignable (CRITICAL), connexions au-dessus du seuil (WARNING).
 */
class DatabaseProvisioner extends ZabbixProvisioner
{
    public const DEFAULT_PORT = 3306;

    public const DEFAULT_CONNECTIONS_WARNING = 100;

    /** Nom d'utilisateur MySQL : il part dans une macro Zabbix, caractères limités par prudence */
    public const USERNAME_PATTERN = '/^[A-Za-z0-9_.@-]{1,80}$/';

    private const CONNECTION = '"{$MYSQL.DSN}","{$MYSQL.USER}","{$MYSQL.PASSWORD}"';

    public const PING_KEY = 'mysql.ping['.self::CONNECTION.']';

    public const STATUS_KEY = 'mysql.get_status_variables['.self::CONNECTION.']';

    public const VERSION_KEY = 'mysql.version['.self::CONNECTION.']';

    /** Temps d'établissement d'une connexion TCP, en secondes, mesuré par zabbix-server */
    public const RESPONSE_KEY = 'net.tcp.service.perf[tcp,{$MYSQL.HOST},{$MYSQL.PORT}]';

    /** Items dépendants de mysql.get_status_variables : nom de la métrique → clé */
    public const KEYS = [
        'connections' => 'mysql.connections',
        'slow_queries' => 'mysql.slow_queries',
        'slow_queries_per_min' => 'mysql.slow_queries.rate',
        'queries_per_sec' => 'mysql.queries.rate',
        'uptime' => 'mysql.uptime',
    ];

    private const ITEM_ZABBIX_AGENT = 0;

    private const ITEM_SIMPLE_CHECK = 3;

    private const ITEM_DEPENDENT = 18;

    private const VALUE_FLOAT = 0;

    private const VALUE_CHAR = 1;

    private const VALUE_UNSIGNED = 3;

    private const VALUE_TEXT = 4;

    private const PREPROCESSING_MULTIPLIER = 1;

    private const PREPROCESSING_CHANGE_PER_SECOND = 10;

    private const PREPROCESSING_JSONPATH = 12;

    private const MACRO_SECRET = 1;

    public function rules(): array
    {
        return [
            'settings.address' => ['required', 'string', 'max:255', new HostOrIp],
            'settings.port' => ['required', 'integer', 'between:1,65535'],
            'settings.username' => ['required', 'string', 'regex:'.self::USERNAME_PATTERN],
            'settings.connections_warning' => ['required', 'integer', 'between:1,100000'],
            'credentials.password' => ['required', 'string', 'max:255'],
        ];
    }

    public function updateRules(): array
    {
        return [...$this->rules(), 'credentials.password' => ['nullable', 'string', 'max:255']];
    }

    public function messages(): array
    {
        return [
            'settings.username.regex' => 'The user name may contain letters, digits, "_", ".", "@" and "-" only.',
        ];
    }

    public function attributes(): array
    {
        return [
            'settings.address' => 'database host',
            'settings.port' => 'port',
            'settings.username' => 'monitoring user',
            'settings.connections_warning' => 'connections threshold',
            'credentials.password' => 'password',
        ];
    }

    /** Interface vers l'agent de la plateforme, qui se connecte à la base ; connexion décrite par les macros. */
    public function hostSettings(MonitoringTarget $target): array
    {
        return [...$this->agentInterface(), 'macros' => $this->macros($target)];
    }

    public function create(MonitoringTarget $target, string $hostId): void
    {
        $interfaceId = $this->agentInterfaceOf($hostId)['interfaceid'];

        $this->zabbix->createItems([
            $this->agentItem($target, $hostId, $interfaceId, 'MySQL: availability', self::PING_KEY, self::VALUE_UNSIGNED),
            $this->agentItem($target, $hostId, $interfaceId, 'MySQL: version', self::VERSION_KEY, self::VALUE_CHAR, '1h'),
            [
                'hostid' => $hostId,
                'interfaceid' => $interfaceId,
                'name' => 'MySQL: TCP response time',
                'key_' => self::RESPONSE_KEY,
                'type' => self::ITEM_SIMPLE_CHECK,
                'value_type' => self::VALUE_FLOAT,
                'units' => 's',
                'delay' => "{$target->check_interval}s",
            ],
        ]);

        [$statusId] = $this->zabbix->createItems([
            ...$this->agentItem($target, $hostId, $interfaceId, 'MySQL: status variables', self::STATUS_KEY, self::VALUE_TEXT),
            'history' => '0',      // non conservé : il ne sert qu'à alimenter les items dépendants
        ]);
        $this->zabbix->createItems($this->dependentItems($hostId, $statusId));

        $this->createChainedTriggers($target);
    }

    public function update(MonitoringTarget $target): void
    {
        $hostId = $target->zabbix_host_id;

        // Hôte, port, utilisateur ou mot de passe : tout passe par les macros du host
        $this->zabbix->updateHost($hostId, ['macros' => $this->macros($target)]);

        $items = $this->zabbix->getItems([
            'output' => ['itemid', 'key_'],
            'hostids' => [$hostId],
            'filter' => ['key_' => [self::PING_KEY, self::STATUS_KEY, self::RESPONSE_KEY]],
        ]);

        if (count($items) !== 3) {
            throw new ZabbixException('MySQL items of the database not found in Zabbix.', 'item.get');
        }

        $this->zabbix->updateItems(array_map(fn (array $item) => ['itemid' => $item['itemid'], 'delay' => "{$target->check_interval}s"], $items));

        $this->updateTriggers($target);
    }

    public function statusItemKeys(): array
    {
        return ['availability' => self::PING_KEY, 'response_time' => self::RESPONSE_KEY];
    }

    public function metricItemKeys(): array
    {
        return [...self::KEYS, 'version' => self::VERSION_KEY];
    }

    public function threshold(MonitoringTarget $target, string $level): int|float|null
    {
        return $level === 'connections_warning' ? $target->setting('connections_warning') : null;
    }

    /** Graphiques (SCRUM-72) : temps de réponse (par défaut), puis connexions avec leur seuil */
    protected function metricSeries(): array
    {
        return [
            ...parent::metricSeries(),
            'connections' => ['label' => 'Connections', 'key' => self::KEYS['connections'], 'unit' => '', 'scale' => 1, 'value_type' => self::VALUE_TYPE_UNSIGNED],
        ];
    }

    public function chartThresholds(MonitoringTarget $target, string $series): array
    {
        return $series === 'connections'
            ? ['warning' => $target->setting('connections_warning'), 'critical' => null]
            : parent::chartThresholds($target, $series);
    }

    public function checkSummary(MonitoringTarget $target): array
    {
        return [
            sprintf('MySQL at %s:%d, user "%s", read by the Zabbix agent (MySQL plugin)',
                $target->setting('address'), $target->setting('port'), $target->setting('username')),
            'Critical when the database cannot be reached',
            sprintf('Warning above %s connections (5-minute average)', number_format((int) $target->setting('connections_warning'))),
            'Response time: TCP connection time measured by the Zabbix server',
        ];
    }

    protected function triggerDefinitions(MonitoringTarget $target): array
    {
        $host = $target->zabbixHostName();
        $ping = "/$host/".self::PING_KEY;
        $connections = "/$host/".self::KEYS['connections'];
        // Pas de nouvelle valeur pendant 3 intervalles (au moins 3 min) : agent ou base injoignable
        $noData = max(180, 3 * $target->check_interval).'s';
        $threshold = (int) $target->setting('connections_warning');

        return [
            'unreachable' => [
                'description' => "{$target->name} database is unreachable",
                'expression' => "last($ping)=0 or nodata($ping,$noData)=1",
                'priority' => self::PRIORITY_DISASTER,
                'tags' => $this->triggerTags($target, 'availability', 'unreachable'),
                'chain' => 'availability',
            ],
            'connections_warning' => [
                'description' => "{$target->name} has more than {$threshold} connections",
                'expression' => "avg($connections,5m)>$threshold",
                'priority' => self::PRIORITY_WARNING,
                'tags' => $this->triggerTags($target, 'connections', 'connections_warning'),
                'chain' => 'connections',
            ],
        ];
    }

    /** Connexion à la base en macros du host ; le mot de passe en macro secrète (jamais renvoyée par l'API). */
    private function macros(MonitoringTarget $target): array
    {
        $host = (string) $target->setting('address');
        $port = (int) $target->setting('port');
        $dsnHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? "[$host]" : $host;

        return [
            ['macro' => '{$MYSQL.DSN}', 'value' => "tcp://$dsnHost:$port"],
            ['macro' => '{$MYSQL.HOST}', 'value' => $host],
            ['macro' => '{$MYSQL.PORT}', 'value' => (string) $port],
            ['macro' => '{$MYSQL.USER}', 'value' => (string) $target->setting('username')],
            ['macro' => '{$MYSQL.PASSWORD}', 'value' => (string) $target->credential('password'), 'type' => self::MACRO_SECRET],
        ];
    }

    private function agentItem(MonitoringTarget $target, string $hostId, string $interfaceId, string $name, string $key, int $valueType, ?string $delay = null): array
    {
        return [
            'hostid' => $hostId,
            'interfaceid' => $interfaceId,
            'name' => $name,
            'key_' => $key,
            'type' => self::ITEM_ZABBIX_AGENT,
            'value_type' => $valueType,
            'delay' => $delay ?? "{$target->check_interval}s",
        ];
    }

    private function dependentItems(string $hostId, string $statusId): array
    {
        $step = fn (int $type, string $params = '') => ['type' => $type, 'params' => $params, 'error_handler' => 0, 'error_handler_params' => ''];
        $item = fn (string $metric, string $name, string $variable, int $valueType, array $extra = [], string $units = '') => [
            'hostid' => $hostId,
            'name' => "MySQL: $name",
            'key_' => self::KEYS[$metric],
            'type' => self::ITEM_DEPENDENT,
            'master_itemid' => $statusId,
            'value_type' => $valueType,
            'units' => $units,
            'preprocessing' => [$step(self::PREPROCESSING_JSONPATH, '$.'.$variable), ...$extra],
        ];

        return [
            $item('connections', 'connections', 'Threads_connected', self::VALUE_UNSIGNED),
            $item('slow_queries', 'slow queries (total)', 'Slow_queries', self::VALUE_UNSIGNED),
            $item('slow_queries_per_min', 'slow queries per minute', 'Slow_queries', self::VALUE_FLOAT,
                [$step(self::PREPROCESSING_CHANGE_PER_SECOND), $step(self::PREPROCESSING_MULTIPLIER, '60')], '/min'),
            $item('queries_per_sec', 'queries per second', 'Questions', self::VALUE_FLOAT, [$step(self::PREPROCESSING_CHANGE_PER_SECOND)], 'qps'),
            $item('uptime', 'uptime', 'Uptime', self::VALUE_UNSIGNED, [], 'uptime'),
        ];
    }
}
