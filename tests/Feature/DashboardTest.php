<?php

namespace Tests\Feature;

use Tests\TestCase;

class DashboardTest extends TestCase
{
    public function test_dashboard_displays_the_kpis(): void
    {
        $this->withoutVite();

        $this->get('/')
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee(['Targets', 'UP', 'Warning', 'Critical', 'Active alerts']);
    }
}
