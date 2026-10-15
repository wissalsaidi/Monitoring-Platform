<?php

namespace Tests\Unit;

use App\Exceptions\Zabbix\ZabbixApiException;
use App\Exceptions\Zabbix\ZabbixAuthenticationException;
use App\Exceptions\Zabbix\ZabbixConnectionException;
use App\Exceptions\Zabbix\ZabbixException;
use App\Services\ZabbixService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

class ZabbixServiceTest extends TestCase
{
    private const URL = 'http://zabbix.test/api_jsonrpc.php';

    private TestHandler $logs;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->logs = new TestHandler;
    }

    private function service(?string $token = 'secret-token', int $retries = 2): ZabbixService
    {
        return new ZabbixService(self::URL, $token, new Logger('zabbix', [$this->logs]), timeout: 5, retries: $retries, retryDelayMs: 0);
    }

    private static function rpcResult(mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'result' => $result, 'id' => 1];
    }

    private static function rpcError(int $code, string $message, string $data): array
    {
        return ['jsonrpc' => '2.0', 'error' => ['code' => $code, 'message' => $message, 'data' => $data], 'id' => 1];
    }

    public function test_it_sends_a_json_rpc_request_with_the_api_token(): void
    {
        Http::fake([self::URL => Http::response(self::rpcResult([['hostid' => '10683', 'name' => 'Payment API']]))]);

        $hosts = $this->service()->getHosts(['filter' => ['host' => ['payment-api']]]);

        $this->assertSame('10683', $hosts[0]['hostid']);
        Http::assertSent(fn (Request $request) => $request->url() === self::URL
            && $request->hasHeader('Authorization', 'Bearer secret-token')
            && $request['jsonrpc'] === '2.0'
            && $request['method'] === 'host.get'
            && $request['params'] === ['output' => 'extend', 'filter' => ['host' => ['payment-api']]]);
    }

    public function test_it_logs_each_successful_call_at_info_level(): void
    {
        Http::fake([self::URL => Http::response(self::rpcResult([]))]);

        $this->service()->getProblems();

        $this->assertTrue($this->logs->hasInfoThatContains('Zabbix API call'));
        $this->assertSame('problem.get', $this->logs->getRecords()[0]->context['method']);
        $this->assertArrayHasKey('duration_ms', $this->logs->getRecords()[0]->context);
    }

    public function test_it_never_logs_the_token_or_the_params(): void
    {
        Http::fake([self::URL => Http::response(self::rpcResult(['hostids' => ['1']]))]);

        $this->service()->createHost(['host' => 'payment-api', 'secret' => 'do-not-log']);

        $logged = json_encode(array_map(fn ($record) => $record->toArray(), $this->logs->getRecords()));
        $this->assertStringNotContainsString('secret-token', $logged);
        $this->assertStringNotContainsString('do-not-log', $logged);
    }

    public function test_api_version_is_requested_without_authorization_header(): void
    {
        Http::fake([self::URL => Http::response(self::rpcResult('7.0.31'))]);

        $this->assertSame('7.0.31', $this->service()->version());
        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
    }

    public function test_create_host_returns_the_new_host_id(): void
    {
        Http::fake([self::URL => Http::response(self::rpcResult(['hostids' => ['10700']]))]);

        $this->assertSame('10700', $this->service()->createHost(['host' => 'payment-api']));
    }

    public function test_it_fails_without_calling_zabbix_when_the_token_is_missing(): void
    {
        Http::fake();

        try {
            $this->service(token: null)->getHosts();
            $this->fail('Exception attendue');
        } catch (ZabbixAuthenticationException $e) {
            $this->assertStringContainsString('ZABBIX_API_TOKEN', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertTrue($this->logs->hasErrorThatContains('Zabbix API call failed'));
    }

    public function test_an_invalid_token_raises_an_authentication_exception(): void
    {
        Http::fake([self::URL => Http::response(self::rpcError(-32602, 'Invalid params.', 'Not authorized.'))]);

        $this->expectException(ZabbixAuthenticationException::class);

        $this->service()->getHosts();
    }

    public function test_an_api_error_raises_an_api_exception_with_zabbix_details(): void
    {
        Http::fake([self::URL => Http::response(self::rpcError(-32602, 'Invalid params.', 'Host with the same name "payment-api" already exists.'))]);

        try {
            $this->service()->createHost(['host' => 'payment-api']);
            $this->fail('Exception attendue');
        } catch (ZabbixApiException $e) {
            $this->assertSame('host.create', $e->method);
            $this->assertSame(-32602, $e->zabbixCode);
            $this->assertStringContainsString('already exists', $e->zabbixData);
            $this->assertSame('Monitoring service temporarily unavailable.', $e->userMessage());
        }

        $this->assertTrue($this->logs->hasErrorThatContains('Zabbix API call failed'));
    }

    public function test_read_requests_are_retried_after_a_server_error(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push('Bad gateway', 502)
            ->push(self::rpcResult([['eventid' => '24']]))]);

        $problems = $this->service()->getProblems();

        $this->assertSame('24', $problems[0]['eventid']);
        Http::assertSentCount(2);
    }

    public function test_it_gives_up_after_the_configured_number_of_retries(): void
    {
        Http::fake([self::URL => Http::response('Service unavailable', 503)]);

        try {
            $this->service(retries: 2)->getProblems();
            $this->fail('Exception attendue');
        } catch (ZabbixConnectionException $e) {
            $this->assertStringContainsString('HTTP 503', $e->getMessage());
        }

        Http::assertSentCount(3); // 1 essai + 2 retries
    }

    public function test_write_requests_are_never_retried(): void
    {
        Http::fake([self::URL => Http::response('Bad gateway', 502)]);

        $this->expectException(ZabbixConnectionException::class);

        try {
            $this->service()->createHost(['host' => 'payment-api']);
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_a_timeout_raises_a_connection_exception_with_a_user_message(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5001 milliseconds'));

        try {
            $this->service(retries: 0)->getProblems();
            $this->fail('Exception attendue');
        } catch (ZabbixConnectionException $e) {
            $this->assertTrue($e->timedOut);
            $this->assertSame('Monitoring request timed out.', $e->userMessage());
        }
    }

    public function test_an_unreachable_server_raises_a_connection_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to zabbix.test'));

        try {
            $this->service(retries: 0)->getProblems();
            $this->fail('Exception attendue');
        } catch (ZabbixConnectionException $e) {
            $this->assertFalse($e->timedOut);
            $this->assertSame('Monitoring service temporarily unavailable.', $e->userMessage());
        }
    }

    public function test_an_invalid_response_raises_a_zabbix_exception(): void
    {
        Http::fake([self::URL => Http::response('<html>Not Zabbix</html>')]);

        $this->expectException(ZabbixException::class);
        $this->expectExceptionMessage('Invalid JSON-RPC response');

        $this->service()->getHosts();
    }

    public function test_the_container_builds_the_service_from_config(): void
    {
        config([
            'services.zabbix.url' => self::URL,
            'services.zabbix.token' => 'from-config',
        ]);
        Http::fake([self::URL => Http::response(self::rpcResult([]))]);

        app(ZabbixService::class)->getHosts();

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer from-config'));
    }
}
