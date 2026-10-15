<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_displays_the_kpis(): void
    {
        $this->withoutVite();

        $this->actingAs(User::factory()->create(['name' => 'Wissal']))
            ->get('/')
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee(['Targets', 'UP', 'Warning', 'Critical', 'Active alerts'])
            ->assertSee(['Wissal', 'Log out']);
    }
}
