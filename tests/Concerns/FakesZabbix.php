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
            'host.create' => ['hostids' => [(string) $this->nextHostId++]],
            'httptest.create' => ['httptestids' => ['30']],
            'trigger.create' => ['triggerids' => [(string) $this->nextTriggerId++]],
            'host.delete' => ['hostids' => $request['params']],
            'problem.get' => $this->zabbixProblems,
            'item.get' => $this->zabbixItems,
            'event.get' => array_values(array_filter(
                $this->zabbixEvents,
                fn ($event) => in_array($event['eventid'], $request['params']['eventids'] ?? [], true),
            )),
            'history.get' => $this->zabbixHistory,
        };

        return Http::response(['jsonrpc' => '2.0', 'result' => $result, 'id' => 1]);
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
