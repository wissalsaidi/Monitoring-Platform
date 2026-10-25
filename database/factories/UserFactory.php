<?php

namespace Database\Factories;

use App\Enums\PlatformRole;
use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Membre d'une team à lui (SCRUM-82) : un compte sans team est renvoyé sur « Waiting for access »,
     * donc un utilisateur « d'une autre team » doit vraiment en avoir une pour recevoir un 403.
     */
    public function withTeam(TeamRole $role = TeamRole::Admin): static
    {
        return $this->afterCreating(fn (User $user) => Team::factory()->create()->members()->attach($user, ['role' => $role]));
    }

    /** Super Admin de la plateforme (SCRUM-81) */
    public function superAdmin(): static
    {
        return $this->state(fn () => ['platform_role' => PlatformRole::SuperAdmin]);
    }
}
