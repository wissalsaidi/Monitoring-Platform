<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

class EditDeleteTargetTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private Team $team;

    private MonitoringTarget $target;

    private const VALID = [
        'name' => 'Payment API',
        'url' => 'http://fake-api:8080/health',
        'check_interval' => 30,
        'warning_threshold_ms' => 500,
        'critical_threshold_ms' => 1000,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeZabbixApi();
        $this->team = Team::factory()->create();
        $this->target = MonitoringTarget::factory()->for($this->team)->create([...self::VALID, 'zabbix_host_id' => '10684']);
    }

    private function member(TeamRole $role = TeamRole::Manager): User
    {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => $role]);

        return $user;
    }

    private function update(array $changes, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->member())
            ->from(route('targets.edit', $this->target))
            ->put(route('targets.update', $this->target), [...self::VALID, ...$changes]);
    }

    // ─── Modification ───────────────────────────────────────────────────

    public function test_the_edit_form_is_prefilled(): void
    {
        $this->actingAs($this->member())
            ->get(route('targets.edit', $this->target))
            ->assertOk()
            ->assertSee(['Edit Payment API', 'value="Payment API"', 'value="http://fake-api:8080/health"', 'value="500"', 'value="1000"', 'Save changes'], false);
    }

    public function test_new_thresholds_and_interval_are_sent_to_zabbix(): void
    {
        $this->update(['check_interval' => 60, 'warning_threshold_ms' => 700, 'critical_threshold_ms' => 1500])
            ->assertRedirect(route('targets.show', [$this->target, 'tab' => 'configuration']))
            ->assertSessionHas('success');

        $this->assertDatabaseHas('monitoring_targets', ['id' => $this->target->id, 'check_interval' => 60, 'warning_threshold_ms' => 700, 'critical_threshold_ms' => 1500]);

        $this->assertSame('60s', $this->callsTo('httptest.update')[0]['delay']);
        $this->assertSame('30', $this->callsTo('httptest.update')[0]['httptestid']);
        $this->assertSame([
            ['triggerid' => '500', 'description' => 'Payment API is unavailable', 'expression' => 'last(/target-'.$this->target->id.'/web.test.fail[api-check])<>0'],
            ['triggerid' => '501', 'description' => 'Payment API response time > 1500 ms', 'expression' => 'last(/target-'.$this->target->id.'/web.test.time[api-check,request,resp])>1.5'],
            ['triggerid' => '502', 'description' => 'Payment API response time > 700 ms', 'expression' => 'last(/target-'.$this->target->id.'/web.test.time[api-check,request,resp])>0.7'],
        ], $this->callsTo('trigger.update')[0]);
    }

    public function test_a_new_name_and_url_are_sent_to_zabbix(): void
    {
        $this->update(['name' => 'Checkout API', 'url' => 'https://checkout.example.com/health']);

        $this->assertSame(['hostid' => '10684', 'name' => 'Checkout API #'.$this->target->id], $this->callsTo('host.update')[0]);
        $this->assertSame('https://checkout.example.com/health', $this->callsTo('httptest.update')[0]['steps'][0]['url']);
        // L'étape est modifiée sur place (httpstepid) : sinon Zabbix recrée l'item et supprime ses triggers
        $this->assertSame('7', $this->callsTo('httptest.update')[0]['steps'][0]['httpstepid']);
        $this->assertSame('Checkout API is unavailable', $this->callsTo('trigger.update')[0][0]['description']);
    }

    public function test_a_description_change_does_not_call_zabbix(): void
    {
        $this->update(['description' => 'Main payment gateway'])->assertSessionHas('success');

        $this->assertSame('Main payment gateway', $this->target->fresh()->description);
        $this->assertSame([], $this->calls);
    }

    public function test_saving_without_change_does_nothing(): void
    {
        $this->update([])->assertSessionHas('success', 'No change to save.');

        $this->assertSame([], $this->calls);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_the_update_is_audited_with_old_and_new_values(): void
    {
        $manager = $this->member();

        $this->update(['critical_threshold_ms' => 1500], $manager);

        $log = AuditLog::sole();
        $this->assertSame('target.update', $log->action);
        $this->assertTrue($log->user->is($manager));
        $this->assertSame(['changes' => ['critical_threshold_ms' => [1000, 1500]]], $log->details);
    }

    public function test_when_zabbix_fails_nothing_is_saved_and_zabbix_is_restored(): void
    {
        $this->failOn('trigger.update');

        $this->update(['critical_threshold_ms' => 1500])
            ->assertRedirect(route('targets.edit', $this->target))
            ->assertSessionHas('error', 'Monitoring service temporarily unavailable. The target was not modified, please try again.');

        $this->assertSame(1000, $this->target->fresh()->critical_threshold_ms);
        // 2e mise à jour du web scenario : remise à l'état d'avant
        $this->assertSame('30s', $this->callsTo('httptest.update')[1]['delay']);
        $this->assertSame(0, AuditLog::count());
    }

    public function test_validation_rules_still_apply(): void
    {
        $this->update(['critical_threshold_ms' => 400])->assertSessionHasErrors('critical_threshold_ms');
        $this->update(['url' => 'not-a-url'])->assertSessionHasErrors('url');

        $this->assertSame([], $this->calls);
    }

    public function test_the_name_stays_unique_in_the_team_but_can_be_kept(): void
    {
        MonitoringTarget::factory()->for($this->team)->create(['name' => 'Search API']);

        $this->update(['name' => 'Search API'])->assertSessionHasErrors('name');
        $this->update(['name' => 'Payment API', 'description' => 'kept'])->assertSessionHasNoErrors();
    }

    public function test_the_type_cannot_be_changed(): void
    {
        $this->update(['type' => 'website']);

        $this->assertSame('api', $this->target->fresh()->type->value);
    }

    // ─── Suppression ────────────────────────────────────────────────────

    public function test_the_target_page_offers_edit_and_delete_with_a_confirmation(): void
    {
        $this->actingAs($this->member())
            ->get(route('targets.show', $this->target))
            ->assertSee([route('targets.edit', $this->target), 'data-bs-target="#delete-target"', 'Delete Payment API?', 'Delete target'], false);
    }

    public function test_deleting_removes_the_zabbix_host_the_target_and_its_alerts(): void
    {
        Alert::factory()->for($this->target, 'target')->count(2)->create();

        $this->actingAs($this->member())
            ->delete(route('targets.destroy', $this->target))
            ->assertRedirect(route('targets.index'))
            ->assertSessionHas('success', 'Payment API deleted, with its Zabbix monitoring and its alerts.');

        $this->assertSame([['10684']], $this->callsTo('host.delete'));
        $this->assertModelMissing($this->target);
        $this->assertSame(0, Alert::count());
    }

    public function test_the_deletion_is_audited(): void
    {
        $manager = $this->member();

        $this->actingAs($manager)->delete(route('targets.destroy', $this->target));

        $log = AuditLog::sole();
        $this->assertSame('target.delete', $log->action);
        $this->assertSame($this->target->id, $log->subject_id);
        $this->assertSame(['name' => 'Payment API', 'url' => 'http://fake-api:8080/health', 'zabbix_host_id' => '10684'], $log->details);
    }

    public function test_when_zabbix_is_down_the_target_is_kept(): void
    {
        $this->zabbixDown = true;

        $this->actingAs($this->member())
            ->from(route('targets.show', $this->target))
            ->delete(route('targets.destroy', $this->target))
            ->assertRedirect(route('targets.show', $this->target))
            ->assertSessionHas('error', 'Monitoring service temporarily unavailable. The target was not deleted, please try again.');

        $this->assertModelExists($this->target);
    }

    public function test_a_host_already_gone_from_zabbix_does_not_block_the_deletion(): void
    {
        $this->failOn('host.delete', 'No permissions to referred object or it does not exist!');
        $this->zabbixHosts = [];

        $this->actingAs($this->member())->delete(route('targets.destroy', $this->target))->assertSessionHas('success');

        $this->assertModelMissing($this->target);
    }

    // ─── Autorisations ──────────────────────────────────────────────────

    public function test_a_viewer_can_neither_edit_nor_delete(): void
    {
        $viewer = $this->member(TeamRole::Viewer);

        $this->actingAs($viewer)->get(route('targets.edit', $this->target))->assertForbidden();
        $this->update(['critical_threshold_ms' => 1500], $viewer)->assertForbidden();
        $this->actingAs($viewer)->delete(route('targets.destroy', $this->target))->assertForbidden();
        $this->actingAs($viewer)->get(route('targets.show', $this->target))
            ->assertDontSee(route('targets.edit', $this->target))
            ->assertDontSee('delete-target');

        $this->assertModelExists($this->target);
        $this->assertSame([], $this->calls);
    }

    public function test_a_manager_of_another_team_cannot_touch_the_target(): void
    {
        $outsider = User::factory()->create();
        Team::factory()->create()->members()->attach($outsider, ['role' => TeamRole::Manager]);

        $this->actingAs($outsider)->get(route('targets.edit', $this->target))->assertForbidden();
        $this->update(['name' => 'Hijacked'], $outsider)->assertForbidden();
        $this->actingAs($outsider)->delete(route('targets.destroy', $this->target))->assertForbidden();

        $this->assertSame('Payment API', $this->target->fresh()->name);
        $this->assertSame([], $this->calls);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->put(route('targets.update', $this->target), self::VALID)->assertRedirect('/login');
        $this->delete(route('targets.destroy', $this->target))->assertRedirect('/login');
    }

    public function test_creating_a_target_is_audited_too(): void
    {
        $manager = $this->member();

        $this->actingAs($manager)->post(route('targets.store'), [...self::VALID, 'type' => 'api', 'name' => 'Search API']);

        $this->assertSame('target.create', AuditLog::sole()->action);
    }
}
