<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use App\Enums\TeamRole;
use App\Http\Controllers\AlertController;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MonitoringTarget $payment;

    private MonitoringTarget $search;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $team = Team::factory()->create();
        $this->user = User::factory()->create();
        $team->members()->attach($this->user, ['role' => TeamRole::Viewer]);
        $this->payment = MonitoringTarget::factory()->for($team)->create(['name' => 'Payment API']);
        $this->search = MonitoringTarget::factory()->for($team)->create(['name' => 'Search API']);
    }

    private function alert(MonitoringTarget $target, array $attributes = []): Alert
    {
        return Alert::factory()->for($target, 'target')->create($attributes);
    }

    private function resolved(MonitoringTarget $target, array $attributes = []): Alert
    {
        return $this->alert($target, [
            'status' => AlertStatus::Resolved,
            'started_at' => now()->subMinutes(30),
            'resolved_at' => now()->subMinutes(18),
            ...$attributes,
        ]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/alerts')->assertRedirect('/login');
        $this->get('/alerts/history')->assertRedirect('/login');
    }

    public function test_the_active_tab_lists_open_and_acknowledged_alerts_with_their_columns(): void
    {
        $this->travelTo('2026-10-08 08:42:00'); // UTC → 10:42 à Paris
        $this->alert($this->payment, ['severity' => AlertSeverity::High, 'value' => 1240, 'threshold' => 1000, 'started_at' => now()]);
        $this->alert($this->search, ['status' => AlertStatus::Acknowledged, 'message' => 'Search API is unavailable', 'metric' => 'availability', 'value' => null, 'threshold' => null]);
        $this->resolved($this->payment, ['message' => 'Old incident']);

        $this->actingAs($this->user)
            ->get('/alerts')
            ->assertOk()
            ->assertSeeInOrder(['Date', 'Target', 'Severity', 'Value', 'Threshold', 'Status', 'Duration'])
            ->assertSeeInOrder(['08/10 10:42', 'Payment API', 'Response time', 'HIGH', '1,240 ms', '&gt; 1,000 ms', 'OPEN', 'ongoing'], false)
            ->assertSeeInOrder(['Search API', 'Availability', 'ACKNOWLEDGED'])
            ->assertViewHas('alerts', fn ($alerts) => $alerts->total() === 2)
            ->assertViewHas('counts', ['active' => 2, 'history' => 1]);
    }

    public function test_the_history_tab_lists_resolved_alerts_with_their_duration(): void
    {
        $this->resolved($this->payment, ['started_at' => now()->subMinutes(30), 'resolved_at' => now()->subMinutes(18)]);
        $this->alert($this->search);

        $this->actingAs($this->user)
            ->get('/alerts/history')
            ->assertOk()
            ->assertSeeInOrder(['Payment API', 'RESOLVED', '12m'])
            ->assertViewHas('alerts', fn ($alerts) => $alerts->total() === 1);
    }

    public function test_the_duration_shows_minutes_and_seconds(): void
    {
        $alert = $this->resolved($this->payment, ['started_at' => '2026-10-08 10:42:00', 'resolved_at' => '2026-10-08 10:54:30']);

        $this->assertSame('12m 30s', $alert->durationForHumans());
    }

    public function test_alerts_are_sorted_newest_first(): void
    {
        $this->alert($this->payment, ['message' => 'Older', 'started_at' => now()->subHour()]);
        $this->alert($this->search, ['message' => 'Newer', 'started_at' => now()->subMinute()]);

        $this->actingAs($this->user)
            ->get('/alerts')
            ->assertSeeInOrder(['Search API', 'Payment API']);
    }

    public function test_it_filters_by_severity(): void
    {
        $this->alert($this->payment, ['severity' => AlertSeverity::Critical]);
        $this->alert($this->search, ['severity' => AlertSeverity::Warning]);

        $this->actingAs($this->user)
            ->get('/alerts?severity=critical')
            ->assertViewHas('alerts', fn ($alerts) => $alerts->total() === 1 && $alerts->first()->target->is($this->payment));
    }

    public function test_it_filters_by_target(): void
    {
        $this->alert($this->payment);
        $this->resolved($this->payment);
        $this->resolved($this->search);

        $this->actingAs($this->user)
            ->get('/alerts/history?target='.$this->search->id)
            ->assertViewHas('alerts', fn ($alerts) => $alerts->total() === 1 && $alerts->first()->target->is($this->search));
    }

    public function test_the_tabs_keep_the_filters(): void
    {
        $this->actingAs($this->user)
            ->get('/alerts?severity=high')
            ->assertSee(route('alerts.history', ['severity' => 'high']));
    }

    public function test_alerts_of_other_teams_are_hidden(): void
    {
        $other = MonitoringTarget::factory()->create(['name' => 'Someone else API']);
        $this->alert($other);

        $this->actingAs($this->user)
            ->get('/alerts')
            ->assertDontSee('Someone else API')
            ->assertSee('All clear')
            ->assertViewHas('counts', ['active' => 0, 'history' => 0]);
    }

    public function test_filtering_on_a_target_of_another_team_is_rejected(): void
    {
        $other = MonitoringTarget::factory()->create();

        $this->actingAs($this->user)
            ->get('/alerts?target='.$other->id)
            ->assertSessionHasErrors('target');
    }

    public function test_an_unknown_severity_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->get('/alerts?severity=apocalyptic')
            ->assertSessionHasErrors('severity');
    }

    public function test_it_paginates(): void
    {
        Alert::factory()->for($this->payment, 'target')->count(AlertController::PER_PAGE + 3)->create();

        $this->actingAs($this->user)
            ->get('/alerts?page=2')
            ->assertViewHas('alerts', fn ($alerts) => $alerts->count() === 3 && $alerts->currentPage() === 2);
    }

    public function test_empty_states(): void
    {
        $this->actingAs($this->user)->get('/alerts')->assertSee('All clear');
        $this->actingAs($this->user)->get('/alerts/history')->assertSee('No resolved alert yet');
        $this->actingAs($this->user)->get('/alerts?severity=info')->assertSee('No alert matches these filters');
    }

    public function test_the_alerts_menu_is_enabled(): void
    {
        $this->actingAs($this->user)
            ->get('/')
            ->assertSee(route('alerts.index'))
            ->assertDontSee('Alerts (soon)');
    }
}
