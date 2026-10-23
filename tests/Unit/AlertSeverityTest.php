<?php

namespace Tests\Unit;

use App\Enums\AlertSeverity;
use App\Enums\TargetStatus;
use App\Monitoring\ZabbixProvisioner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AlertSeverityTest extends TestCase
{
    public static function zabbixSeverities(): array
    {
        return [
            'not classified' => [0, AlertSeverity::Info],
            'information' => [1, AlertSeverity::Info],
            'warning' => [2, AlertSeverity::Warning],
            'average' => [3, AlertSeverity::Warning],
            'high' => [4, AlertSeverity::High],
            'disaster' => [5, AlertSeverity::Critical],
            'string from the API' => ['4', AlertSeverity::High],
        ];
    }

    #[DataProvider('zabbixSeverities')]
    public function test_it_maps_zabbix_severities(int|string $zabbix, AlertSeverity $expected): void
    {
        $this->assertSame($expected, AlertSeverity::fromZabbix($zabbix));
    }

    public function test_it_rejects_an_unknown_zabbix_severity(): void
    {
        $this->expectException(\ValueError::class);

        AlertSeverity::fromZabbix(6);
    }

    public function test_the_triggers_created_by_the_platform_map_to_warning_high_and_critical(): void
    {
        $this->assertSame(AlertSeverity::Warning, AlertSeverity::fromZabbix(ZabbixProvisioner::PRIORITY_WARNING));
        $this->assertSame(AlertSeverity::High, AlertSeverity::fromZabbix(ZabbixProvisioner::PRIORITY_HIGH));
        $this->assertSame(AlertSeverity::Critical, AlertSeverity::fromZabbix(ZabbixProvisioner::PRIORITY_DISASTER));
    }

    public function test_each_severity_gives_a_target_status(): void
    {
        $this->assertSame(TargetStatus::Up, AlertSeverity::Info->targetStatus());
        $this->assertSame(TargetStatus::Warning, AlertSeverity::Warning->targetStatus());
        $this->assertSame(TargetStatus::Critical, AlertSeverity::High->targetStatus());
        $this->assertSame(TargetStatus::Critical, AlertSeverity::Critical->targetStatus());
    }

    public function test_severities_are_ordered(): void
    {
        $ranks = array_map(fn (AlertSeverity $s) => $s->rank(), AlertSeverity::cases());

        $this->assertSame([0, 1, 2, 3], $ranks);
    }
}
