<?php

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Enums\TeamRole;
use App\Http\Controllers\MonitoringTargetController;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TargetListTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->team = Team::factory()->create();
        $this->user = User::factory()->create();
        $this->team->members()->attach($this->user, ['role' => TeamRole::Viewer]);
    }

    private function target(array $attributes = []): MonitoringTarget
    {
        return MonitoringTarget::factory()->for($this->team)->create($attributes);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/targets')->assertRedirect('/login');
    }

    public function test_it_lists_the_team_targets_with_status_type_and_last_check(): void
    {
        $this->travelTo(now()->startOfMinute());
        $this->target([
            'name' => 'Payment API',
            'status' => TargetStatus::Critical,
            'last_checked_at' => now()->subSeconds(30),
            'last_response_ms' => 1240.5,
        ]);
        $this->target(['name' => 'Search API']);

        $this->actingAs($this->user)
            ->get('/targets')
            ->assertOk()
            // Sans filtre de type : la métrique principale de chaque target (SCRUM-72), le temps de réponse pour une API
            ->assertSeeInOrder(['Name', 'Type', 'Status', 'Key metric', 'Last check'])
            ->assertSeeInOrder(['Payment API', 'API', 'bi-x-octagon-fill', 'CRITICAL', '1,241 ms', '30 seconds ago'])
            ->assertSeeInOrder(['Search API', 'UNKNOWN', 'Never']);
    }

    public function test_targets_of_other_teams_are_hidden(): void
    {
        $this->target(['name' => 'Mine']);
        MonitoringTarget::factory()->create(['name' => 'Someone else API']);

        $this->actingAs($this->user)
            ->get('/targets')
            ->assertSee('Mine')
            ->assertDontSee('Someone else API');
    }

    public function test_it_searches_by_name(): void
    {
        $this->target(['name' => 'Payment API']);
        $this->target(['name' => 'Payment worker']);
        $this->target(['name' => 'Search API']);

        $this->actingAs($this->user)
            ->get('/targets?q=payment')
            ->assertSee(['Payment API', 'Payment worker'])
            ->assertDontSee('Search API');
    }

    public function test_like_wildcards_in_the_search_are_literal(): void
    {
        $this->target(['name' => 'Payment API']);

        $this->actingAs($this->user)
            ->get('/targets?q=%25')
            ->assertDontSee('Payment API')
            ->assertSee('No target matches these filters');
    }

    public function test_it_filters_by_type(): void
    {
        $this->target(['name' => 'Payment API', 'type' => TargetType::Api]);
        $this->target(['name' => 'Company website', 'type' => TargetType::Website]);

        $this->actingAs($this->user)
            ->get('/targets?type=website')
            ->assertSee('Company website')
            ->assertDontSee('Payment API');
    }

    public function test_an_unknown_type_is_rejected(): void
    {
        $this->actingAs($this->user)
            ->get('/targets?type=spaceship')
            ->assertSessionHasErrors('type');
    }

    public function test_it_paginates_and_keeps_the_filters_in_the_links(): void
    {
        foreach (range(1, MonitoringTargetController::PER_PAGE + 2) as $i) {
            $this->target(['name' => sprintf('API %02d', $i)]);
        }

        $this->actingAs($this->user)
            ->get('/targets?q=API')
            ->assertSee(['API 01', 'API 15'])
            ->assertDontSee('API 16')
            ->assertSee('targets?q=API&amp;page=2', false);

        $this->actingAs($this->user)
            ->get('/targets?q=API&page=2')
            ->assertSee(['API 16', 'API 17'])
            ->assertDontSee('API 15');
    }

    public function test_the_empty_state_invites_managers_to_add_a_target(): void
    {
        $manager = User::factory()->create();
        $this->team->members()->attach($manager, ['role' => TeamRole::Manager]);

        $this->actingAs($manager)
            ->get('/targets')
            ->assertSee(['No target monitored yet', 'Add Monitoring Target']);
    }

    public function test_viewers_do_not_get_the_add_button(): void
    {
        $this->actingAs($this->user)
            ->get('/targets')
            ->assertDontSee('Add Monitoring Target');
    }

    public function test_the_dashboard_counts_active_alerts_and_links_to_the_list(): void
    {
        $target = $this->target();
        Alert::factory()->for($target, 'target')->count(2)->create();
        Alert::factory()->for($target, 'target')->create(['status' => 'resolved']);
        Alert::factory()->create(); // autre team

        $this->actingAs($this->user)
            ->get('/')
            ->assertViewHas('activeAlerts', 2)
            ->assertSee(route('targets.index'));
    }
}
