<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ZabbixCheckCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['services.zabbix.url' => 'http://zabbix.test/api_jsonrpc.php', 'services.zabbix.token' => 'token']);
        Http::preventStrayRequests();
    }

    public function test_it_reports_a_working_connection(): void
    {
        Http::fake(['zabbix.test/*' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'result' => '7.0.31', 'id' => 1])
            ->push(['jsonrpc' => '2.0', 'result' => [['hostid' => '1', 'name' => 'Payment API']], 'id' => 2])]);

        $this->artisan('zabbix:check')
            ->expectsOutputToContain('7.0.31')
            ->expectsOutputToContain('OK')
            ->assertSuccessful();
    }

    public function test_it_fails_when_the_token_is_rejected(): void
    {
        Http::fake(['zabbix.test/*' => Http::sequence()
            ->push(['jsonrpc' => '2.0', 'result' => '7.0.31', 'id' => 1])
            ->push(['jsonrpc' => '2.0', 'error' => ['code' => -32602, 'message' => 'Invalid params.', 'data' => 'Not authorized.'], 'id' => 2])]);

        $this->artisan('zabbix:check')
            ->expectsOutputToContain('ZabbixAuthenticationException')
            ->assertFailed();
    }
}
