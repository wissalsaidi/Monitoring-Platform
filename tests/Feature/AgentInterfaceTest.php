<?php

namespace Tests\Feature;

use App\Models\MonitoringTarget;
use App\Monitoring\ZabbixProvisioner;
use App\Services\ZabbixService;
use Tests\TestCase;

/**
 * Interface agent des hosts (SCRUM-67) : par défaut l'agent Zabbix 2 de la plateforme (config monitoring.agent).
 */
class AgentInterfaceTest extends TestCase
{
    /** Provisioner minimal qui expose agentInterface() */
    private function provisioner(): object
    {
        return new class(app(ZabbixService::class)) extends ZabbixProvisioner
        {
            public function interface(?string $address = null, ?int $port = null): array
            {
                return $this->agentInterface($address, $port)['interfaces'][0];
            }

            public function rules(): array
            {
                return [];
            }

            public function create(MonitoringTarget $target, string $hostId): void {}

            public function update(MonitoringTarget $target): void {}

            public function statusItemKeys(): array
            {
                return ['availability' => 'agent.ping', 'response_time' => null];
            }

            public function threshold(MonitoringTarget $target, string $level): int|float|null
            {
                return null;
            }

            protected function triggerDefinitions(MonitoringTarget $target): array
            {
                return [];
            }
        };
    }

    public function test_defaults_to_the_platform_agent(): void
    {
        config(['monitoring.agent' => ['host' => 'zabbix-agent2', 'port' => 10050]]);

        $this->assertSame([
            'type' => ZabbixProvisioner::INTERFACE_AGENT,
            'main' => 1,
            'useip' => 0,
            'ip' => '',
            'dns' => 'zabbix-agent2',
            'port' => '10050',
        ], $this->provisioner()->interface());
    }

    public function test_an_ip_address_is_declared_as_ip(): void
    {
        $interface = $this->provisioner()->interface('10.0.0.12', 10055);

        $this->assertSame(1, $interface['useip']);
        $this->assertSame('10.0.0.12', $interface['ip']);
        $this->assertSame('', $interface['dns']);
        $this->assertSame('10055', $interface['port']);
    }

    public function test_a_host_name_is_declared_as_dns_with_the_default_port(): void
    {
        config(['monitoring.agent.port' => 10060]);

        $interface = $this->provisioner()->interface('db-01.internal');

        $this->assertSame(0, $interface['useip']);
        $this->assertSame('db-01.internal', $interface['dns']);
        $this->assertSame('10060', $interface['port']);
    }
}
