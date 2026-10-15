<?php

namespace Database\Factories;

use App\Enums\TargetType;
use App\Models\MonitoringTarget;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MonitoringTarget>
 */
class MonitoringTargetFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->words(2, true).' API',
            'type' => TargetType::Api,
            'url' => fake()->url(),
            'check_interval' => 60,
            'warning_threshold_ms' => 500,
            'critical_threshold_ms' => 1000,
        ];
    }
}
