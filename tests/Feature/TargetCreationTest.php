<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

class TargetCreationTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private const VALID = [
        'type' => 'api',
        'name' => 'Payment API',
        'url' => 'http://fake-api:8080/health',
        'check_interval' => 30,
        'warning_threshold_ms' => 500,
        'critical_threshold_ms' => 1000,
    ];

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeZabbixApi();
        $this->team = Team::factory()->create();
    }

    private function member(TeamRole $role = TeamRole::Manager): User
    {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => $role]);

        return $user;
    }

    public function test_guests_cannot_access_the_form(): void
    {
        $this->get('/targets/create')->assertRedirect('/login');
        $this->post('/targets', self::VALID)->assertRedirect('/login');
    }

    public function test_a_manager_sees_the_form(): void
    {
        $this->actingAs($this->member())
            ->get('/targets/create')
            ->assertOk()
            ->assertSee(['Add Monitoring Target', 'Check interval', 'Warning threshold', 'Critical threshold', 'Create Monitoring']);
    }

    public function test_a_manager_creates_a_target_in_their_team(): void
    {
        $user = $this->member();

        $response = $this->actingAs($user)->post('/targets', self::VALID);

        $target = MonitoringTarget::sole();
        $response->assertRedirect(route('targets.show', $target))
            ->assertSessionHas('success');
        $this->assertSame($this->team->id, $target->team_id);
        $this->assertSame($user->id, $target->created_by);
        $this->assertSame('10700', $target->zabbix_host_id);
        $this->assertCount(1, $this->callsTo('httptest.create'));
    }

    public function test_an_admin_can_create_a_target_too(): void
    {
        $this->actingAs($this->member(TeamRole::Admin))->post('/targets', self::VALID);

        $this->assertDatabaseCount('monitoring_targets', 1);
    }

    public function test_a_viewer_cannot_create_a_target(): void
    {
        $viewer = $this->member(TeamRole::Viewer);

        $this->actingAs($viewer)->get('/targets/create')->assertForbidden();
        $this->actingAs($viewer)->post('/targets', self::VALID)->assertForbidden();

        $this->assertDatabaseCount('monitoring_targets', 0);
        $this->assertSame([], $this->calls);
    }

    public function test_the_target_page_shows_the_new_target(): void
    {
        $this->actingAs($user = $this->member())->post('/targets', self::VALID);

        $this->actingAs($user)
            ->get(route('targets.show', MonitoringTarget::sole()))
            ->assertOk()
            ->assertSee(['Payment API', 'http://fake-api:8080/health', 'UNKNOWN', '500', '1,000']);
    }

    public function test_users_cannot_see_targets_of_other_teams(): void
    {
        $foreign = MonitoringTarget::factory()->create();

        $this->actingAs($this->member())->get(route('targets.show', $foreign))->assertForbidden();
    }

    /**
     * @dataProvider invalidInputs
     */
    public function test_invalid_input_is_rejected(array $override, string $field): void
    {
        $this->actingAs($this->member())
            ->from('/targets/create')
            ->post('/targets', [...self::VALID, ...$override])
            ->assertRedirect('/targets/create')
            ->assertSessionHasErrors($field);

        $this->assertDatabaseCount('monitoring_targets', 0);
        $this->assertSame([], $this->calls, 'Zabbix ne doit pas être appelé si la saisie est invalide');
    }

    public static function invalidInputs(): array
    {
        return [
            'nom manquant' => [['name' => ''], 'name'],
            'URL invalide' => [['url' => 'not a url'], 'url'],
            'URL non HTTP' => [['url' => 'ftp://example.com'], 'url'],
            'critical <= warning' => [['warning_threshold_ms' => 1000, 'critical_threshold_ms' => 1000], 'critical_threshold_ms'],
            'seuil trop grand' => [['critical_threshold_ms' => 20000], 'critical_threshold_ms'],
            'seuil négatif' => [['warning_threshold_ms' => -5], 'warning_threshold_ms'],
            'intervalle non proposé' => [['check_interval' => 0], 'check_interval'],
            'type pas encore disponible' => [['type' => 'network'], 'type'],
        ];
    }

    public function test_the_name_must_be_unique_within_the_team(): void
    {
        MonitoringTarget::factory()->for($this->team)->create(['name' => 'Payment API']);

        $this->actingAs($this->member())->post('/targets', self::VALID)
            ->assertSessionHasErrors(['name' => 'Your team already has a target with this name.']);
    }

    public function test_the_same_name_is_allowed_in_another_team(): void
    {
        MonitoringTarget::factory()->create(['name' => 'Payment API']);

        $this->actingAs($this->member())->post('/targets', self::VALID)->assertSessionHasNoErrors();
    }

    public function test_when_zabbix_is_down_the_form_comes_back_with_a_clean_message(): void
    {
        $this->zabbixDown = true;

        $this->actingAs($this->member())
            ->from('/targets/create')
            ->post('/targets', self::VALID)
            ->assertRedirect('/targets/create')
            ->assertSessionHas('error', fn (string $message) => str_starts_with($message, 'Monitoring service temporarily unavailable.'))
            ->assertSessionHasInput('name', 'Payment API');

        $this->assertDatabaseCount('monitoring_targets', 0);
    }

    public function test_the_dashboard_counts_and_lists_the_team_targets(): void
    {
        $user = $this->member();
        MonitoringTarget::factory()->count(2)->for($this->team)->create();
        MonitoringTarget::factory()->create(['name' => 'Someone else API']);

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee('Add Monitoring Target')
            ->assertViewHas('total', 2)
            ->assertDontSee('Someone else API');
    }
}
