<?php

namespace App\Services;

use App\Exceptions\Zabbix\ZabbixApiException;
use App\Exceptions\Zabbix\ZabbixAuthenticationException;
use App\Exceptions\Zabbix\ZabbixConnectionException;
use App\Exceptions\Zabbix\ZabbixException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;

/**
 * Point d'entrée unique vers l'API JSON-RPC de Zabbix (cahier des charges §35).
 * Aucun controller ne doit appeler Zabbix directement.
 */
class ZabbixService
{
    /** Méthodes appelables sans authentification (Zabbix refuse le header Authorization dessus). */
    private const PUBLIC_METHODS = ['apiinfo.version'];

    /** Messages d'erreur Zabbix qui signifient un problème d'authentification. */
    private const AUTH_ERRORS = ['not authorized', 'session terminated', 'api token expired', 'login name or password is incorrect'];

    /** event.acknowledge : actions combinables (masque de bits) */
    public const ACK_ACKNOWLEDGE = 2;

    public const ACK_ADD_MESSAGE = 4;

    private int $requestId = 1;

    public function __construct(
        private readonly string $url,
        private readonly ?string $token,
        private readonly LoggerInterface $logger,
        private readonly int $timeout = 15,
        private readonly int $retries = 2,
        private readonly int $retryDelayMs = 200,
    ) {}

    public function version(): string
    {
        return $this->call('apiinfo.version');
    }

    public function getHostGroups(array $params = []): array
    {
        return $this->call('hostgroup.get', ['output' => 'extend', ...$params]);
    }

    /** @return string id du host group créé */
    public function createHostGroup(string $name): string
    {
        return $this->call('hostgroup.create', ['name' => $name])['groupids'][0];
    }

    public function getHosts(array $params = []): array
    {
        return $this->call('host.get', ['output' => 'extend', ...$params]);
    }

    /** @return string id du host créé */
    public function createHost(array $params): string
    {
        return $this->call('host.create', $params)['hostids'][0];
    }

    public function updateHost(string $hostId, array $params): void
    {
        $this->call('host.update', ['hostid' => $hostId, ...$params]);
    }

    public function deleteHost(string ...$hostIds): void
    {
        $this->call('host.delete', $hostIds);
    }

    /** @return string id du web scenario créé */
    public function createWebScenario(array $params): string
    {
        return $this->call('httptest.create', $params)['httptestids'][0];
    }

    /**
     * @param  array  $triggers  un trigger ou une liste de triggers
     * @return string[] ids des triggers créés
     */
    public function createTriggers(array $triggers): array
    {
        return $this->call('trigger.create', $triggers)['triggerids'];
    }

    public function getItems(array $params = []): array
    {
        return $this->call('item.get', ['output' => 'extend', ...$params]);
    }

    public function getTriggers(array $params = []): array
    {
        return $this->call('trigger.get', ['output' => 'extend', ...$params]);
    }

    public function getProblems(array $params = []): array
    {
        return $this->call('problem.get', ['output' => 'extend', ...$params]);
    }

    public function getEvents(array $params = []): array
    {
        return $this->call('event.get', ['output' => 'extend', ...$params]);
    }

    /** Acquitte les événements et y ajoute un message (visible dans Zabbix). */
    public function acknowledgeEvents(array $eventIds, string $message): void
    {
        $this->call('event.acknowledge', [
            'eventids' => $eventIds,
            'action' => self::ACK_ACKNOWLEDGE | self::ACK_ADD_MESSAGE,
            'message' => $message,
        ]);
    }

    /** Tendances horaires (min / moyenne / max par heure) */
    public function getTrends(array $params = []): array
    {
        return $this->call('trend.get', $params);
    }

    public function getHistory(array $params = []): array
    {
        return $this->call('history.get', ['output' => 'extend', ...$params]);
    }

    /**
     * Appel JSON-RPC brut. Renvoie le champ "result" de la réponse.
     *
     * @throws ZabbixConnectionException Zabbix injoignable, timeout, erreur HTTP
     * @throws ZabbixAuthenticationException token absent, invalide ou expiré
     * @throws ZabbixApiException erreur renvoyée par l'API
     * @throws ZabbixException réponse illisible
     */
    public function call(string $method, array $params = []): mixed
    {
        $authenticated = ! in_array($method, self::PUBLIC_METHODS, true);
        $startedAt = hrtime(true);

        if ($authenticated && blank($this->token)) {
            throw $this->failed(new ZabbixAuthenticationException('Zabbix API token is not configured (ZABBIX_API_TOKEN).', $method), $startedAt);
        }

        try {
            $response = $this->request($method, $authenticated)->post($this->url, [
                'jsonrpc' => '2.0',
                'method' => $method,
                'params' => $params,
                'id' => $this->requestId++,
            ]);
        } catch (ConnectionException $e) {
            $timedOut = str_contains($e->getMessage(), 'cURL error 28');

            throw $this->failed(new ZabbixConnectionException(
                ($timedOut ? 'Zabbix API timed out: ' : 'Zabbix API unreachable: ').$e->getMessage(),
                $method,
                $timedOut,
                $e,
            ), $startedAt);
        }

        if ($response->failed()) {
            throw $this->failed(new ZabbixConnectionException("Zabbix API returned HTTP {$response->status()}.", $method), $startedAt);
        }

        $body = $response->json();

        if (isset($body['error'])) {
            throw $this->failed($this->apiError($method, $body['error']), $startedAt);
        }

        if (! is_array($body) || ! array_key_exists('result', $body)) {
            throw $this->failed(new ZabbixException('Invalid JSON-RPC response from Zabbix API.', $method), $startedAt);
        }

        $this->logger->info('Zabbix API call', ['method' => $method, 'duration_ms' => $this->elapsedMs($startedAt)]);

        return $body['result'];
    }

    private function request(string $method, bool $authenticated): PendingRequest
    {
        return Http::asJson()
            ->acceptJson()
            ->timeout($this->timeout)
            ->connectTimeout(min(5, $this->timeout))
            ->when($authenticated, fn (PendingRequest $request) => $request->withToken($this->token))
            // On ne rejoue que les lectures : un host.create rejoué après un timeout pourrait créer un doublon.
            ->when($this->isReadOnly($method), fn (PendingRequest $request) => $request->retry(
                $this->retries + 1,
                $this->retryDelayMs,
                fn (\Throwable $e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()),
                throw: false,
            ));
    }

    private function isReadOnly(string $method): bool
    {
        return str_ends_with($method, '.get') || $method === 'apiinfo.version';
    }

    private function apiError(string $method, array $error): ZabbixException
    {
        $data = (string) ($error['data'] ?? '');
        $message = trim(($error['message'] ?? 'Unknown error').' '.$data);

        foreach (self::AUTH_ERRORS as $authError) {
            if (str_contains(strtolower($data), $authError)) {
                return new ZabbixAuthenticationException("Zabbix authentication failed: $message", $method);
            }
        }

        return new ZabbixApiException("Zabbix API error on $method: $message", $method, (int) ($error['code'] ?? 0), $data);
    }

    private function failed(ZabbixException $e, int $startedAt): ZabbixException
    {
        $this->logger->error('Zabbix API call failed', [
            'method' => $e->method,
            'duration_ms' => $this->elapsedMs($startedAt),
            'exception' => class_basename($e),
            'error' => $e->getMessage(),
        ]);

        return $e;
    }

    private function elapsedMs(int $startedAt): int
    {
        return intdiv(hrtime(true) - $startedAt, 1_000_000);
    }
}
