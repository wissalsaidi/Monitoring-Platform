<?php
/**
 * POC SCRUM-18 : piloter Zabbix uniquement via son API (JSON-RPC).
 *
 * Usage :
 *   php poc.php setup     -> crée le groupe, le host, le web scenario et les triggers
 *   php poc.php status    -> affiche les derniers temps de réponse et les problèmes actifs
 *   php poc.php cleanup   -> supprime le host créé par le POC
 *
 * Config (variables d'environnement, valeurs par défaut = docker-compose du POC) :
 *   ZABBIX_URL, ZABBIX_USER, ZABBIX_PASSWORD, TARGET_URL
 *
 * Ce script deviendra plus tard le ZabbixService de Laravel.
 */

const HOST_NAME     = 'payment-api';
const GROUP_NAME    = 'POC Monitoring Platform';
const SCENARIO_NAME = 'health-check';
const STEP_NAME     = 'health';
const WARNING_S     = 0.5;  // seuil warning  : 500 ms
const CRITICAL_S    = 1.0;  // seuil critical : 1000 ms

$zabbixUrl = getenv('ZABBIX_URL') ?: 'http://localhost:8080/api_jsonrpc.php';
$user      = getenv('ZABBIX_USER') ?: 'Admin';
$password  = getenv('ZABBIX_PASSWORD') ?: 'zabbix';
$targetUrl = getenv('TARGET_URL') ?: 'http://fake-api:8080/health'; // vu depuis le conteneur Zabbix

final class ZabbixClient
{
    private ?string $token = null;
    private int $id = 1;

    public function __construct(private string $url) {}

    public function login(string $user, string $password): void
    {
        $this->token = $this->call('user.login', ['username' => $user, 'password' => $password]);
    }

    public function call(string $method, array $params = []): mixed
    {
        $headers = ['Content-Type: application/json-rpc'];
        if ($this->token !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POSTFIELDS     => json_encode([
                'jsonrpc' => '2.0',
                'method'  => $method,
                'params'  => $params,
                'id'      => $this->id++,
            ]),
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Zabbix injoignable ($err). Zabbix est-il démarré ?");
        }
        $response = json_decode($raw, true);
        if (isset($response['error'])) {
            throw new RuntimeException("$method : {$response['error']['message']} {$response['error']['data']}");
        }
        return $response['result'];
    }
}

function findHostId(ZabbixClient $zbx): ?string
{
    $hosts = $zbx->call('host.get', ['output' => ['hostid'], 'filter' => ['host' => [HOST_NAME]]]);
    return $hosts[0]['hostid'] ?? null;
}

function setup(ZabbixClient $zbx, string $targetUrl): void
{
    // 1. Groupe d'hôtes (plus tard : un groupe par team)
    $groups = $zbx->call('hostgroup.get', ['output' => ['groupid'], 'filter' => ['name' => [GROUP_NAME]]]);
    $groupId = $groups[0]['groupid']
        ?? $zbx->call('hostgroup.create', ['name' => GROUP_NAME])['groupids'][0];
    echo "✔ Groupe          : " . GROUP_NAME . " (id $groupId)\n";

    // 2. Host (on repart de zéro à chaque setup)
    if ($old = findHostId($zbx)) {
        $zbx->call('host.delete', [$old]);
    }
    $hostId = $zbx->call('host.create', [
        'host'   => HOST_NAME,
        'name'   => 'Payment API',
        'groups' => [['groupid' => $groupId]],
    ])['hostids'][0];
    echo "✔ Host            : Payment API (id $hostId)\n";

    // 3. Web scenario : Zabbix appelle l'URL toutes les 30 s
    $zbx->call('httptest.create', [
        'name'   => SCENARIO_NAME,
        'hostid' => $hostId,
        'delay'  => '30s',
        'steps'  => [[
            'no'           => 1,
            'name'         => STEP_NAME,
            'url'          => $targetUrl,
            'status_codes' => '200',
            'timeout'      => '15s',
        ]],
    ]);
    echo "✔ Web scenario    : $targetUrl toutes les 30 s\n";

    // 4. Triggers (Zabbix mesure le temps de réponse en secondes)
    $time = sprintf('/%s/web.test.time[%s,%s,resp]', HOST_NAME, SCENARIO_NAME, STEP_NAME);
    $fail = sprintf('/%s/web.test.fail[%s]', HOST_NAME, SCENARIO_NAME);
    $zbx->call('trigger.create', [
        [
            'description' => 'Payment API lente (> ' . WARNING_S * 1000 . ' ms)',
            'expression'  => "last($time)>" . WARNING_S,
            'priority'    => 2, // Warning
        ],
        [
            'description' => 'Payment API très lente (> ' . CRITICAL_S * 1000 . ' ms)',
            'expression'  => "last($time)>" . CRITICAL_S,
            'priority'    => 4, // High
        ],
        [
            'description' => 'Payment API indisponible',
            'expression'  => "last($fail)<>0",
            'priority'    => 5, // Disaster
        ],
    ]);
    echo "✔ Triggers        : warning > 500 ms, high > 1000 ms, indisponible\n\n";
    echo "Attends ~1 min que Zabbix fasse ses premiers checks, puis : php poc.php status\n";
}

function status(ZabbixClient $zbx): void
{
    $hostId = findHostId($zbx) ?? throw new RuntimeException('Host introuvable, lance d\'abord : php poc.php setup');

    // Derniers temps de réponse
    $items = $zbx->call('item.get', [
        'output'   => ['itemid', 'lastvalue', 'lastclock'],
        'hostids'  => $hostId,
        'webitems' => true, // sans ça, item.get ignore les items créés par un web scenario
        'filter'   => ['key_' => sprintf('web.test.time[%s,%s,resp]', SCENARIO_NAME, STEP_NAME)],
    ]);
    if ($items) {
        $history = $zbx->call('history.get', [
            'itemids'   => $items[0]['itemid'],
            'history'   => 0, // valeurs décimales
            'sortfield' => 'clock',
            'sortorder' => 'DESC',
            'limit'     => 5,
        ]);
        echo "Derniers temps de réponse :\n";
        foreach ($history ?: [] as $h) {
            printf("  %s  →  %4d ms\n", date('H:i:s', (int) $h['clock']), round($h['value'] * 1000));
        }
        if (!$history) {
            echo "  (pas encore de mesure, patiente un peu)\n";
        }
    }

    // Problèmes actifs
    $severities = ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'];
    $problems = $zbx->call('problem.get', [
        'output'    => ['eventid', 'name', 'severity', 'clock'],
        'hostids'   => $hostId,
        'sortfield' => ['eventid'],
        'sortorder' => 'DESC',
    ]);
    echo "\nProblèmes actifs :\n";
    foreach ($problems as $p) {
        printf("  🔴 [%s] %s (depuis %s, event #%s)\n",
            $severities[$p['severity']], $p['name'], date('H:i:s', (int) $p['clock']), $p['eventid']);
    }
    if (!$problems) {
        echo "  🟢 aucun\n";
    }
}

function cleanup(ZabbixClient $zbx): void
{
    if ($hostId = findHostId($zbx)) {
        $zbx->call('host.delete', [$hostId]);
        echo "✔ Host supprimé\n";
    } else {
        echo "Rien à supprimer\n";
    }
}

$command = $argv[1] ?? 'help';
try {
    $zbx = new ZabbixClient($zabbixUrl);
    if ($command !== 'help') {
        $zbx->login($user, $password);
    }
    match ($command) {
        'setup'   => setup($zbx, $targetUrl),
        'status'  => status($zbx),
        'cleanup' => cleanup($zbx),
        default   => print("Usage : php poc.php setup|status|cleanup\n"),
    };
} catch (Throwable $e) {
    fwrite(STDERR, "✘ " . $e->getMessage() . "\n");
    exit(1);
}
