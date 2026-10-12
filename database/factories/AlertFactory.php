<?php

namespace Database\Factories;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Alert>
 */
class AlertFactory extends Factory
{
    public function definition(): array
    {
        return [
            'monitoring_target_id' => MonitoringTarget::factory(),
            'zabbix_event_id' => (string) fake()->unique()->numberBetween(1000, 999999),
            'severity' => AlertSeverity::High,
            'status' => AlertStatus::Open,
            'message' => 'Response time > 1000 ms',
            'metric' => 'response_time',
            'threshold' => 1000,
            'started_at' => now()->subMinutes(5),
        ];
    }
}
