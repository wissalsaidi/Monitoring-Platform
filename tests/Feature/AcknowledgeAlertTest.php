<?php

namespace Tests\Feature;

use App\Enums\AlertStatus;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

class AcknowledgeAlertTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private Team $team;

    private Alert $alert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeZabbixApi();
        $this->team = Team::factory()->create();
        $target = MonitoringTarget::factory()->for($this->team)->create(['name' => 'Payment API']);
        $this->alert = Alert::factory()->for($target, 'target')->create(['zabbix_event_id' => '9001']);
    }

    private function member(TeamRole $role, string $name = 'Wissal'): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->team->members()->attach($user, ['role' => $role]);

        return $user;
    }

    private function acknowledge(User $user)
    {
        return $this->actingAs($user)
            ->from(route('alerts.index'))
            ->post(route('alerts.acknowledge', $this->alert));
    }

    public function test_a_manager_acknowledges_an_open_alert(): void
    {
        $this->freezeSecond();
        $manager = $this->member(TeamRole::Manager);

        $this->acknowledge($manager)
            ->assertRedirect(route('alerts.index'))
            ->assertSessionHas('success', 'Alert on Payment API acknowledged.');

        $alert = $this->alert->fresh();
        $this->assertSame(AlertStatus::Acknowledged, $alert->status);
        $this->assertTrue($alert->acknowledged_at->equalTo(now()));
        $this->assertTrue($alert->acknowledger->is($manager));
    }

    public function test_the_acknowledgement_is_sent_to_zabbix(): void
    {
        $this->acknowledge($this->member(TeamRole::Manager));

        $this->assertSame([[
            'eventids' => ['9001'],
            'action' => 6, // acquitter (2) + ajouter un message (4)
            'message' => 'Acknowledged by Wissal from Monitoring Platform',
        ]], $this->callsTo('event.acknowledge'));
    }

    public function test_the_acknowledgement_is_audited(): void
    {
        $manager = $this->member(TeamRole::Manager);

        $this->acknowledge($manager);

        $log = AuditLog::sole();
        $this->assertSame('alert.acknowledge', $log->action);
        $this->assertTrue($log->user->is($manager));
        $this->assertTrue($log->subject->is($this->alert));
        $this->assertSame(['target' => 'Payment API', 'severity' => 'high', 'zabbix_event_id' => '9001'], $log->details);
        $this->assertSame('127.0.0.1', $log->ip_address);
    }

    public function test_an_admin_can_acknowledge_too(): void
    {
        $this->acknowledge($this->member(TeamRole::Admin))->assertSessionHas('success');

        $this->assertSame(AlertStatus::Acknowledged, $this->alert->fresh()->status);
    }

    public function test_a_viewer_cannot_acknowledge(): void
    {
        $this->acknowledge($this->member(TeamRole::Viewer))->assertForbidden();

        $this->assertSame(AlertStatus::Open, $this->alert->fresh()->status);
        $this->assertSame([], $this->callsTo('event.acknowledge'));
    }

    public function test_a_member_of_another_team_cannot_acknowledge(): void
    {
        $outsider = User::factory()->create();
        Team::factory()->create()->members()->attach($outsider, ['role' => TeamRole::Manager]);

        $this->acknowledge($outsider)->assertForbidden();
    }

    public function test_only_open_alerts_can_be_acknowledged(): void
    {
        $this->alert->update(['status' => AlertStatus::Resolved, 'resolved_at' => now()]);

        $this->acknowledge($this->member(TeamRole::Manager))->assertForbidden();
        $this->assertSame([], $this->callsTo('event.acknowledge'));
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->post(route('alerts.acknowledge', $this->alert))->assertRedirect('/login');
    }

    public function test_when_zabbix_is_down_nothing_changes_and_a_clean_message_is_shown(): void
    {
        $this->zabbixDown = true;

        $this->acknowledge($this->member(TeamRole::Manager))
            ->assertRedirect(route('alerts.index'))
            ->assertSessionHas('error', 'Monitoring service temporarily unavailable. The alert was not acknowledged, please try again.');

        $this->assertSame(AlertStatus::Open, $this->alert->fresh()->status);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_an_event_already_acknowledged_in_zabbix_is_accepted(): void
    {
        $this->failOn('event.acknowledge', 'Cannot update "acknowledged": problem is already acknowledged.');
        $this->zabbixEvents = [['eventid' => '9001', 'acknowledged' => '1']];

        $this->acknowledge($this->member(TeamRole::Manager))->assertSessionHas('success');

        $this->assertSame(AlertStatus::Acknowledged, $this->alert->fresh()->status);
    }

    public function test_a_zabbix_refusal_on_an_unacknowledged_event_is_reported(): void
    {
        $this->failOn('event.acknowledge');
        $this->zabbixEvents = [['eventid' => '9001', 'acknowledged' => '0']];

        $this->acknowledge($this->member(TeamRole::Manager))->assertSessionHas('error');

        $this->assertSame(AlertStatus::Open, $this->alert->fresh()->status);
    }

    public function test_managers_see_the_button_on_open_alerts_only(): void
    {
        Alert::factory()->for($this->alert->target, 'target')->create(['status' => AlertStatus::Acknowledged, 'acknowledged_at' => now()]);

        $html = $this->actingAs($this->member(TeamRole::Manager))
            ->get(route('alerts.index'))
            ->assertSee(route('alerts.acknowledge', $this->alert))
            ->getContent();

        $this->assertSame(1, substr_count($html, 'Acknowledge</button>'));
    }

    public function test_viewers_do_not_see_the_button(): void
    {
        $this->actingAs($this->member(TeamRole::Viewer))
            ->get(route('alerts.index'))
            ->assertDontSee(route('alerts.acknowledge', $this->alert));
    }

    public function test_the_author_and_date_are_shown_on_acknowledged_alerts(): void
    {
        $this->travelTo('2026-10-15 08:05:00'); // 10:05 à Paris
        $manager = $this->member(TeamRole::Manager, 'Sara');
        $this->acknowledge($manager);

        $this->actingAs($manager)
            ->get(route('targets.show', $this->alert->target))
            ->assertSeeInOrder(['ACKNOWLEDGED', 'by Sara · 15/10 10:05']);
    }

    public function test_the_target_page_offers_the_button_too(): void
    {
        $this->actingAs($this->member(TeamRole::Manager))
            ->get(route('targets.show', $this->alert->target))
            ->assertSee(route('alerts.acknowledge', $this->alert));
    }
}
