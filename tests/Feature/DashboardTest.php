<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\TargetStatus;
use App\Enums\TeamRole;
use App\Http\Controllers\DashboardController;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Http::preventStrayRequests(); // le dashboard ne doit jamais appeler Zabbix
        $this->team = Team::factory()->create();
        $this->user = User::factory()->create(['name' => 'Wissal']);
        $this->team->members()->attach($this->user, ['role' => TeamRole::Viewer]);
    }

    private function target(TargetStatus $status, string $name = 'API'): MonitoringTarget
    {
        return MonitoringTarget::factory()->for($this->team)->create(['status' => $status, 'name' => $name]);
    }

    public function test_dashboard_displays_the_kpis(): void
    {
        $this->actingAs($this->user)
            ->get('/')
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee(['Targets', 'UP', 'Warning', 'Critical', 'Unknown', 'Active alerts'])
            ->assertSee(['Wissal', 'Log out']);
    }

    public function test_the_kpis_count_the_targets_by_status_and_the_active_alerts(): void
    {
        $this->target(TargetStatus::Up);
        $this->target(TargetStatus::Up);
        $warning = $this->target(TargetStatus::Warning);
        $critical = $this->target(TargetStatus::Critical);
        $this->target(TargetStatus::Unknown);
        Alert::factory()->for($warning, 'target')->create(['severity' => AlertSeverity::Warning]);
        Alert::factory()->for($critical, 'target')->create(['status' => AlertStatus::Acknowledged]);
        Alert::factory()->for($critical, 'target')->create(['status' => AlertStatus::Resolved, 'resolved_at' => now()]);
        // Autre team : jamais comptée
        $foreign = MonitoringTarget::factory()->create(['status' => TargetStatus::Critical]);
        Alert::factory()->for($foreign, 'target')->create();

        $this->actingAs($this->user)
            ->get('/')
            ->assertViewHas('total', 5)
            ->assertViewHas('counts', fn ($counts) => $counts->all() === ['unknown' => 1, 'up' => 2, 'warning' => 1, 'critical' => 1])
            ->assertViewHas('activeAlerts', 2);
    }

    public function test_each_kpi_card_links_to_the_matching_list(): void
    {
        $this->actingAs($this->user)
            ->get('/')
            ->assertSee([
                route('targets.index', ['status' => 'up']),
                route('targets.index', ['status' => 'warning']),
                route('targets.index', ['status' => 'critical']),
                route('targets.index', ['status' => 'unknown']),
                route('alerts.index'),
            ]);
    }

    public function test_targets_in_trouble_are_listed_first(): void
    {
        $this->target(TargetStatus::Up, 'Alpha API');
        $this->target(TargetStatus::Unknown, 'Bravo API');
        $this->target(TargetStatus::Critical, 'Charlie API');
        $this->target(TargetStatus::Warning, 'Delta API');

        $this->actingAs($this->user)
            ->get('/')
            ->assertSeeInOrder(['Charlie API', 'Delta API', 'Bravo API', 'Alpha API']);
    }

    public function test_the_latest_alerts_are_shown_at_the_bottom_newest_first(): void
    {
        $target = $this->target(TargetStatus::Warning, 'Payment API');
        Alert::factory()->for($target, 'target')->create(['severity' => AlertSeverity::Warning, 'value' => 712, 'started_at' => now()->subMinute()]);
        Alert::factory()->for($target, 'target')->create([
            'severity' => AlertSeverity::Critical, 'metric' => 'availability', 'value' => null, 'threshold' => null,
            'status' => AlertStatus::Resolved, 'started_at' => now()->subHour(), 'resolved_at' => now()->subMinutes(50),
        ]);

        $this->actingAs($this->user)
            ->get('/')
            ->assertSeeInOrder(['Monitoring targets', 'Latest alerts', 'Response time', 'WARNING', '712 ms', 'OPEN', 'Availability', 'CRITICAL', 'RESOLVED', '10m'])
            ->assertSee(route('alerts.history'));
    }

    public function test_only_the_most_recent_alerts_are_shown(): void
    {
        $target = $this->target(TargetStatus::Up);
        Alert::factory()->for($target, 'target')->count(DashboardController::RECENT_ALERTS + 4)->create();

        $this->actingAs($this->user)
            ->get('/')
            ->assertViewHas('recentAlerts', fn ($alerts) => $alerts->count() === DashboardController::RECENT_ALERTS);
    }

    public function test_the_empty_states(): void
    {
        $this->actingAs($this->user)
            ->get('/')
            ->assertSee(['No target monitored yet', 'No alert yet']);
    }

    public function test_the_page_needs_few_queries_whatever_the_number_of_targets(): void
    {
        // Chargement < 1 s : nombre de requêtes constant (pas de N+1), aucune requête Zabbix.
        foreach (range(1, 30) as $i) {
            $target = $this->target(TargetStatus::Warning, "API $i");
            Alert::factory()->for($target, 'target')->create();
        }

        DB::enableQueryLog();
        $this->actingAs($this->user)->get('/')->assertOk();
        $queries = count(DB::getQueryLog());

        $this->assertLessThanOrEqual(12, $queries, "The dashboard ran $queries queries.");
    }
}
