<?php

namespace Database\Factories;

use App\Enums\AlertSeverity;
use App\Enums\NotificationEvent;
use App\Enums\WebhookType;
use App\Models\Team;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->unique()->words(2, true),
            'type' => WebhookType::Generic,
            'url' => 'https://hooks.example.com/'.fake()->uuid(),
            // Par défaut : CRITICAL et HIGH, ouverture et résolution
            'severities' => [AlertSeverity::Critical, AlertSeverity::High],
            'events' => NotificationEvent::cases(),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['active' => false]);
    }
}
