<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Faux Zabbix pour les tests : répond aux méthodes JSON-RPC utilisées et garde la trace des appels.
 * Appeler $this->fakeZabbixApi() dans setUp().
 */
trait FakesZabbix
{
    protected string $zabbixUrl = 'http://zabbix.test/api_jsonrpc.php';

    /** @var array<int, array{method: string, params: mixed}> appels reçus par le faux Zabbix */
    protected array $calls = [];

    /** @var array<string, array> réponses en erreur par méthode */
    protected array $failures = [];

    protected int $nextHostId = 10700;

    protected int $nextTriggerId = 500;

    protected int $nextItemId = 800;

    /** Host group déjà présent dans Zabbix (renvoyé par hostgroup.get) */
    protected ?string $existingGroupId = null;

    /** Simule un Zabbix en panne (HTTP 502) */
    protected bool $zabbixDown = false;

    /** @var array<int, array> réponse de problem.get */
    protected array $zabbixProblems = [];

    /** @var array<int, array> réponse de item.get */
    protected array $zabbixItems = [];

    /** @var array<int, array> événements connus de event.get (filtrés par eventids) */
    protected array $zabbixEvents = [];

    /** @var array<int, array> réponse de history.get */
    protected array $zabbixHistory = [];

    /** @var array<int, array> réponse de trend.get */
    protected array $zabbixTrends = [];

    /** @var array<int, array> réponse de httptest.get */
    protected array $zabbixScenarios = [['httptestid' => '30', 'steps' => [['httpstepid' => '7']]]];

    /** @var array<int, array> réponse de trigger.get : les 3 triggers d'une target, reconnus par leur tag "level" */
    protected array $zabbixTriggers = [
        ['triggerid' => '500', 'tags' => [['tag' => 'level', 'value' => 'unavailable']]],
        ['triggerid' => '501', 'tags' => [['tag' => 'level', 'value' => 'critical']]],
        ['triggerid' => '502', 'tags' => [['tag' => 'level', 'value' => 'warning']]],
    ];

    /** @var array<int, array> réponse de host.get */
    protected array $zabbixHosts = [];

    /** @var array<string, array> groupes d'utilisateurs Zabbix existants (usrgrpid → groupe), SCRUM-48 */
    protected array $zabbixUserGroups = [];

    /** @var array<string, array> utilisateurs Zabbix existants (userid → utilisateur), SCRUM-48 */
    protected array $zabbixUsers = [];

    protected function fakeZabbixApi(): void
    {
        config(['services.zabbix.url' => $this->zabbixUrl, 'services.zabbix.token' => 'token', 'services.zabbix.retry_delay' => 0]);
        Http::preventStrayRequests();
        Http::fake([$this->zabbixUrl => fn (Request $request) => $this->fakeZabbix($request)]);
    }

    /** Faux Zabbix : répond selon la méthode JSON-RPC et garde la trace des appels. */
    protected function fakeZabbix(Request $request)
    {
        $method = $request['method'];
        $this->calls[] = ['method' => $method, 'params' => $request['params']];

        if ($this->zabbixDown) {
            return Http::response('Bad gateway', 502);
        }

        if (isset($this->failures[$method])) {
            return Http::response(['jsonrpc' => '2.0', 'error' => $this->failures[$method], 'id' => 1]);
        }

        $result = match ($method) {
            'hostgroup.get' => $this->existingGroupId ? [['groupid' => $this->existingGroupId]] : [],
            'hostgroup.create' => ['groupids' => ['42']],
            'hostgroup.delete' => ['groupids' => $request['params']],
            'host.create' => ['hostids' => [(string) $this->nextHostId++]],
            'httptest.create' => ['httptestids' => ['30']],
            'trigger.create' => ['triggerids' => [(string) $this->nextTriggerId++]],
            'host.delete' => ['hostids' => $request['params']],
            'problem.get' => $this->zabbixProblems,
            'item.get' => $this->zabbixItems,
            'item.create' => ['itemids' => array_map(fn () => (string) $this->nextItemId++, array_is_list($request['params']) ? $request['params'] : [1])],
            'item.update' => ['itemids' => array_column($request['params'], 'itemid')],
            'item.delete' => ['itemids' => $request['params']],
            'task.create' => ['taskids' => array_keys($request['params'])],
            'template.get' => [['templateid' => '10001', 'host' => 'Linux by Zabbix agent']],
            'hostinterface.update' => ['interfaceids' => [$request['params']['interfaceid']]],
            'event.get' => array_values(array_filter(
                $this->zabbixEvents,
                fn ($event) => in_array($event['eventid'], $request['params']['eventids'] ?? [], true),
            )),
            'history.get' => $this->zabbixHistory,
            'trend.get' => $this->zabbixTrends,
            'event.acknowledge' => ['eventids' => $request['params']['eventids']],
            'host.get' => $this->zabbixHosts,
            'host.update' => ['hostids' => [$request['params']['hostid']]],
            'httptest.get' => $this->zabbixScenarios,
            'httptest.update' => ['httptestids' => [$request['params']['httptestid']]],
            'trigger.get' => $this->zabbixTriggers,
            'trigger.update' => ['triggerids' => array_column($request['params'], 'triggerid')],
            'usergroup.get' => array_values(array_intersect_key($this->zabbixUserGroups, array_flip($request['params']['usrgrpids'] ?? array_keys($this->zabbixUserGroups)))),
            'usergroup.create' => ['usrgrpids' => [$this->store($this->zabbixUserGroups, 'usrgrpid', $request['params'])]],
            'usergroup.delete' => ['usrgrpids' => $this->forget($this->zabbixUserGroups, $request['params'])],
            'user.get' => array_values(array_intersect_key($this->zabbixUsers, array_flip($request['params']['userids'] ?? array_keys($this->zabbixUsers)))),
            'user.create' => ['userids' => [$this->store($this->zabbixUsers, 'userid', $request['params'])]],
            'user.update' => ['userids' => [$request['params']['userid']]],
            'user.delete' => ['userids' => $this->forget($this->zabbixUsers, $request['params'])],
        };

        return Http::response(['jsonrpc' => '2.0', 'result' => $result, 'id' => 1]);
    }

    /** Enregistre un objet créé (groupe, utilisateur) et renvoie son id */
    private function store(array &$objects, string $idField, array $params): string
    {
        $id = (string) (90 + count($this->calls));
        $objects[$id] = [$idField => $id, ...$params];

        return $id;
    }

    private function forget(array &$objects, array $ids): array
    {
        foreach ($ids as $id) {
            unset($objects[$id]);
        }

        return $ids;
    }

    protected function failOn(string $method, string $data = 'Something went wrong.'): void
    {
        $this->failures[$method] = ['code' => -32602, 'message' => 'Invalid params.', 'data' => $data];
    }

    /** @return array<int, mixed> paramètres des appels à une méthode */
    protected function callsTo(string $method): array
    {
        return array_values(array_map(
            fn ($call) => $call['params'],
            array_filter($this->calls, fn ($call) => $call['method'] === $method),
        ));
    }
}
