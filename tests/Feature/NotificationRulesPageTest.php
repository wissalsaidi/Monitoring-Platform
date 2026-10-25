<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\TeamRole;
use App\Models\AlertRule;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use App\Services\NotificationRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Page « Notifications » (SCRUM-55) : règles par sévérité et par événement, modifiables par Admin et Manager.
 */
class NotificationRulesPageTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->team = Team::factory()->create(['name' => 'Payments']);
        $this->admin = User::factory()->create();
        $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin]);
    }

    private function member(TeamRole $role): User
    {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => $role]);

        return $user;
    }

    /** Formulaire complet : les règles actuelles, avec quelques changements */
    private function form(array $overrides = []): array
    {
        $rules = [];
        foreach (AlertRule::where('team_id', $this->team->id)->get() as $rule) {
            $rules[$rule->severity->value][$rule->event->value] = $rule->channel->value;
        }

        return ['rules' => array_replace_recursive($rules, $overrides)];
    }

    private function channel(string $severity, string $event): NotificationChannel
    {
        return AlertRule::where(['team_id' => $this->team->id, 'severity' => $severity, 'event' => $event])->sole()->channel;
    }

    public function test_the_page_shows_one_choice_per_severity_and_event(): void
    {
        $response = $this->actingAs($this->admin)->get(route('teams.notifications.index', $this->team));

        $response->assertOk()
            ->assertSeeInOrder(['CRITICAL', 'HIGH', 'WARNING', 'INFO'])
            ->assertSee('When an alert is opened')
            ->assertSee('When an alert is resolved')
            ->assertSee('Save');
        // Défauts : CRITICAL et HIGH par email (4 selects), WARNING et INFO au dashboard (4 selects)
        $this->assertSame(4, substr_count($response->getContent(), 'value="email" selected'));
        $this->assertSame(4, substr_count($response->getContent(), 'value="dashboard" selected'));
    }

    public function test_the_menu_link_opens_the_default_team(): void
    {
        $this->actingAs($this->admin)->get(route('teams.notifications.current'))
            ->assertRedirect(route('teams.notifications.index', $this->team));
    }

    public function test_a_viewer_sees_the_rules_without_being_able_to_change_them(): void
    {
        $viewer = $this->member(TeamRole::Viewer);

        $this->actingAs($viewer)->get(route('teams.notifications.index', $this->team))
            ->assertOk()
            ->assertSee('Read only')
            ->assertSee('disabled', false)
            ->assertDontSee('Save');

        $this->actingAs($viewer)->put(route('teams.notifications.update', $this->team), $this->form(['high' => ['resolved' => 'dashboard']]))
            ->assertForbidden();
        $this->assertSame(NotificationChannel::Email, $this->channel('high', 'resolved'));
    }

    public static function managingRoles(): array
    {
        return [[TeamRole::Admin], [TeamRole::Manager]];
    }

    #[DataProvider('managingRoles')]
    public function test_admins_and_managers_change_the_rules(TeamRole $role): void
    {
        $user = $role === TeamRole::Admin ? $this->admin : $this->member($role);

        $this->actingAs($user)->from(route('teams.notifications.index', $this->team))
            ->put(route('teams.notifications.update', $this->team), $this->form([
                'high' => ['resolved' => 'dashboard'],
                'warning' => ['opened' => 'email'],
            ]))
            ->assertRedirect(route('teams.notifications.index', $this->team))
            ->assertSessionHas('success', 'Notification rules saved.');

        $this->assertSame(NotificationChannel::Dashboard, $this->channel('high', 'resolved'));
        $this->assertSame(NotificationChannel::Email, $this->channel('warning', 'opened'));
        $this->assertSame(NotificationChannel::Email, $this->channel('high', 'opened'));
        $this->assertCount(8, AlertRule::where('team_id', $this->team->id)->get());
    }

    public function test_each_change_is_recorded_in_the_audit_log(): void
    {
        $this->actingAs($this->admin)->put(route('teams.notifications.update', $this->team), $this->form([
            'high' => ['resolved' => 'dashboard'],
            'info' => ['opened' => 'email'],
        ]));

        $log = AuditLog::sole();
        $this->assertSame('notification_rule.update', $log->action);
        $this->assertSame($this->team->id, $log->team_id);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame([
            'high_resolved' => ['Email', 'Dashboard only'],
            'info_opened' => ['Dashboard only', 'Email'],
        ], $log->details['changes']);

        // Lisible sur la page Audit log
        $this->actingAs($this->admin)->get(route('teams.audit.index', $this->team))
            ->assertSee('Notification rules updated')
            ->assertSeeInOrder(['High resolved', 'Email', 'Dashboard only', 'Info opened', 'Dashboard only', 'Email']);
    }

    public function test_saving_without_change_records_nothing(): void
    {
        $this->actingAs($this->admin)->put(route('teams.notifications.update', $this->team), $this->form())
            ->assertSessionHas('success', 'No change.');

        $this->assertCount(0, AuditLog::all());
    }

    public function test_an_invalid_or_missing_channel_is_refused(): void
    {
        $form = $this->form(['critical' => ['opened' => 'sms']]);
        unset($form['rules']['info']['resolved']);

        $this->actingAs($this->admin)->put(route('teams.notifications.update', $this->team), $form)
            ->assertSessionHasErrors(['rules.critical.opened', 'rules.info.resolved']);

        $this->assertSame(NotificationChannel::Email, $this->channel('critical', 'opened'));
        $this->assertCount(0, AuditLog::all());
    }

    public function test_a_missing_rule_is_shown_as_dashboard_only_and_created_on_save(): void
    {
        AlertRule::where(['team_id' => $this->team->id, 'severity' => 'critical', 'event' => 'resolved'])->delete();

        $response = $this->actingAs($this->admin)->get(route('teams.notifications.index', $this->team));
        $this->assertSame(3, substr_count($response->getContent(), 'value="email" selected'));

        $this->actingAs($this->admin)->put(route('teams.notifications.update', $this->team), $this->form(['critical' => ['resolved' => 'email']]));

        $this->assertSame(NotificationChannel::Email, $this->channel('critical', 'resolved'));
        $this->assertSame([null, 'Email'], AuditLog::sole()->details['changes']['critical_resolved']);
    }

    public function test_the_rules_of_another_team_are_out_of_reach(): void
    {
        $other = Team::factory()->create();

        $this->actingAs($this->admin)->get(route('teams.notifications.index', $other))->assertForbidden();
        $this->actingAs($this->admin)->put(route('teams.notifications.update', $other), $this->form())->assertForbidden();
    }

    public function test_the_new_rules_drive_the_router(): void
    {
        $this->actingAs($this->admin)->put(route('teams.notifications.update', $this->team), $this->form(['critical' => ['opened' => 'dashboard']]));

        $router = app(NotificationRouter::class);
        $this->assertSame(NotificationChannel::Dashboard, $router->channelFor($this->team, AlertSeverity::Critical, NotificationEvent::Opened));
    }
}
