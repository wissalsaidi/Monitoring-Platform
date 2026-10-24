<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\AlertRule;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\NotificationRouter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Règles de notification et choix des destinataires (SCRUM-52).
 */
class NotificationRoutingTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    private User $viewer;

    private MonitoringTarget $target;

    private NotificationRouter $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->admin = User::factory()->create(['email' => 'admin@example.com']);
        $this->viewer = User::factory()->create(['email' => 'viewer@example.com']);
        $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin]);
        $this->team->members()->attach($this->viewer, ['role' => TeamRole::Viewer]);
        $this->target = MonitoringTarget::factory()->for($this->team)->create();
        $this->router = app(NotificationRouter::class);
    }

    private function alert(AlertSeverity $severity = AlertSeverity::Critical): Alert
    {
        return Alert::factory()->for($this->target, 'target')->create(['severity' => $severity]);
    }

    private function setRule(AlertSeverity $severity, NotificationEvent $event, NotificationChannel $channel): void
    {
        AlertRule::where(['team_id' => $this->team->id, 'severity' => $severity->value, 'event' => $event->value])->update(['channel' => $channel->value]);
    }

    public function test_a_new_team_gets_the_default_rules(): void
    {
        $rules = $this->team->alertRules()->get()->mapWithKeys(fn (AlertRule $rule) => ["{$rule->severity->value}.{$rule->event->value}" => $rule->channel]);

        $this->assertCount(8, $rules);
        foreach (['critical', 'high'] as $severity) {
            $this->assertSame(NotificationChannel::Email, $rules["$severity.opened"]);
            $this->assertSame(NotificationChannel::Email, $rules["$severity.resolved"]);
        }
        foreach (['warning', 'info'] as $severity) {
            $this->assertSame(NotificationChannel::Dashboard, $rules["$severity.opened"]);
            $this->assertSame(NotificationChannel::Dashboard, $rules["$severity.resolved"]);
        }
    }

    public function test_a_team_has_one_rule_per_severity_and_event(): void
    {
        $this->expectException(QueryException::class);

        AlertRule::create(['team_id' => $this->team->id, 'severity' => 'critical', 'event' => 'opened', 'channel' => 'dashboard']);
    }

    public function test_the_channel_follows_the_rule_of_the_team(): void
    {
        $this->setRule(AlertSeverity::High, NotificationEvent::Resolved, NotificationChannel::Dashboard);

        $this->assertSame(NotificationChannel::Email, $this->router->channelFor($this->team, AlertSeverity::High, NotificationEvent::Opened));
        $this->assertSame(NotificationChannel::Dashboard, $this->router->channelFor($this->team, AlertSeverity::High, NotificationEvent::Resolved));
        $this->assertSame(NotificationChannel::Dashboard, $this->router->channelFor($this->team, AlertSeverity::Warning, NotificationEvent::Opened));
    }

    public function test_without_rule_nothing_is_sent(): void
    {
        $this->team->alertRules()->delete();

        $this->assertSame(NotificationChannel::Dashboard, $this->router->channelFor($this->team, AlertSeverity::Critical, NotificationEvent::Opened));
        $this->assertCount(0, $this->router->recipients($this->alert(), NotificationEvent::Opened));
    }

    public function test_every_verified_member_of_the_team_receives_the_email_whatever_their_role(): void
    {
        $recipients = $this->router->recipients($this->alert(AlertSeverity::High), NotificationEvent::Opened);

        $this->assertSame(['admin@example.com', 'viewer@example.com'], $recipients->pluck('email')->all());
    }

    public function test_unverified_members_and_other_teams_are_not_recipients(): void
    {
        $unverified = User::factory()->unverified()->create(['email' => 'new@example.com']);
        $this->team->members()->attach($unverified, ['role' => TeamRole::Manager]);
        $otherTeam = Team::factory()->create();
        $otherTeam->members()->attach(User::factory()->create(['email' => 'other@example.com']), ['role' => TeamRole::Admin]);

        $recipients = $this->router->recipients($this->alert(), NotificationEvent::Opened)->pluck('email');

        $this->assertNotContains('new@example.com', $recipients);
        $this->assertNotContains('other@example.com', $recipients);
    }

    public function test_a_dashboard_only_rule_has_no_recipient(): void
    {
        $this->assertCount(0, $this->router->recipients($this->alert(AlertSeverity::Warning), NotificationEvent::Opened));
    }

    public function test_a_changed_rule_is_followed(): void
    {
        $this->setRule(AlertSeverity::Warning, NotificationEvent::Opened, NotificationChannel::Email);
        $this->setRule(AlertSeverity::Critical, NotificationEvent::Opened, NotificationChannel::Dashboard);

        $this->assertCount(2, $this->router->recipients($this->alert(AlertSeverity::Warning), NotificationEvent::Opened));
        $this->assertCount(0, $this->router->recipients($this->alert(AlertSeverity::Critical), NotificationEvent::Opened));
    }

    public function test_planning_creates_one_pending_notification_per_recipient(): void
    {
        $alert = $this->alert();

        $planned = $this->router->plan($alert, NotificationEvent::Opened);

        $this->assertCount(2, $planned);
        $this->assertSame(['admin@example.com', 'viewer@example.com'], $planned->pluck('recipient')->all());
        $this->assertTrue($planned->every(fn (AlertNotification $n) => $n->status === NotificationStatus::Pending
            && $n->channel === NotificationChannel::Email && $n->event === NotificationEvent::Opened && $n->alert_id === $alert->id));
        $this->assertSame([$this->admin->id, $this->viewer->id], $planned->pluck('user_id')->all());
    }

    public function test_a_notification_is_never_planned_twice_for_the_same_event(): void
    {
        $alert = $this->alert();
        $this->router->plan($alert, NotificationEvent::Opened);

        $this->assertCount(0, $this->router->plan($alert, NotificationEvent::Opened));
        $this->assertSame(2, AlertNotification::count());

        // Autre événement de la même alerte : nouvelles notifications
        $this->assertCount(2, $this->router->plan($alert, NotificationEvent::Resolved));
    }

    public function test_the_database_refuses_a_duplicate_notification(): void
    {
        $alert = $this->alert();
        $this->router->plan($alert, NotificationEvent::Opened);

        $this->expectException(QueryException::class);

        AlertNotification::create(['alert_id' => $alert->id, 'recipient' => 'admin@example.com', 'channel' => 'email', 'event' => 'opened']);
    }

    public function test_a_new_member_receives_the_next_notifications_only(): void
    {
        $alert = $this->alert();
        $this->router->plan($alert, NotificationEvent::Opened);
        $this->team->members()->attach(User::factory()->create(['email' => 'late@example.com']), ['role' => TeamRole::Viewer]);

        $this->assertSame(['late@example.com'], $this->router->plan($alert, NotificationEvent::Opened)->pluck('recipient')->all());
    }

    public function test_deleting_an_account_keeps_its_notifications_without_the_email(): void
    {
        $this->router->plan($this->alert(), NotificationEvent::Opened);

        app(AccountDeletionService::class)->delete($this->viewer);

        $this->assertSame(['admin@example.com', 'deleted user'], AlertNotification::orderBy('id')->pluck('recipient')->all());
        $this->assertNull(AlertNotification::orderBy('id')->get()[1]->user_id);
    }
}
