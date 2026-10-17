<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Page « Audit log » et purge (SCRUM-60).
 */
class AuditLogPageTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    private MonitoringTarget $target;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->team = Team::factory()->create(['name' => 'Platform']);
        $this->admin = User::factory()->create(['name' => 'Wissal']);
        $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin]);
        $this->target = MonitoringTarget::factory()->for($this->team)->create(['name' => 'Payment API']);
    }

    private function page(array $query = [], ?User $user = null)
    {
        return $this->actingAs($user ?? $this->admin)->get(route('teams.audit.index', [$this->team, ...$query]));
    }

    // ─── Enregistrement de la team ──────────────────────────────────────

    public function test_the_team_is_stored_with_each_entry(): void
    {
        $alert = Alert::factory()->for($this->target, 'target')->create();

        $this->assertSame($this->team->id, AuditLog::record('target.update', $this->admin, $this->target)->team_id);
        $this->assertSame($this->team->id, AuditLog::record('alert.acknowledge', $this->admin, $alert)->team_id);
        $this->assertSame($this->team->id, AuditLog::record('member.invite', $this->admin, $this->team)->team_id);
    }

    public function test_an_entry_stays_visible_after_its_object_is_deleted(): void
    {
        AuditLog::record('target.delete', $this->admin, $this->target, ['name' => 'Payment API', 'url' => 'https://pay.example.com']);
        $this->target->delete();

        $this->page()->assertOk()->assertSee('Target deleted')->assertSee('Payment API (deleted)');
    }

    // ─── Accès ──────────────────────────────────────────────────────────

    public function test_the_admin_sees_the_log_from_the_menu(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(route('teams.audit.current'));
        $this->actingAs($this->admin)->get(route('teams.audit.current'))->assertRedirect(route('teams.audit.index', $this->team));
    }

    public static function nonAdminRoles(): array
    {
        return ['manager' => [TeamRole::Manager], 'viewer' => [TeamRole::Viewer]];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_managers_and_viewers_cannot_read_the_log(TeamRole $role): void
    {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => $role]);

        $this->actingAs($user)->get(route('dashboard'))->assertDontSee(route('teams.audit.current'));
        $this->actingAs($user)->get(route('teams.audit.current'))->assertForbidden();
        $this->page(user: $user)->assertForbidden();
    }

    public function test_only_the_actions_of_my_team_are_listed(): void
    {
        $otherTeam = Team::factory()->create();
        $otherTarget = MonitoringTarget::factory()->for($otherTeam)->create(['name' => 'Secret API']);
        AuditLog::record('target.create', $this->admin, $this->target, ['name' => 'Payment API']);
        AuditLog::record('target.create', User::factory()->create(), $otherTarget, ['name' => 'Secret API']);

        $this->page()->assertSee('Payment API')->assertDontSee('Secret API');

        // Admin d'une autre team : pas d'accès à ce journal
        $outsider = User::factory()->create();
        $otherTeam->members()->attach($outsider, ['role' => TeamRole::Admin]);
        $this->page(user: $outsider)->assertForbidden();
    }

    // ─── Contenu ────────────────────────────────────────────────────────

    public function test_each_row_shows_date_author_action_object_and_details(): void
    {
        $this->travelTo('2026-10-17 08:15:30'); // UTC → 10:15:30 à Paris
        AuditLog::record('target.update', $this->admin, $this->target, ['changes' => ['critical_threshold_ms' => [1000, 1500]]]);

        $this->page()->assertSeeInOrder(['17/10/2026 10:15:30', 'Wissal', 'Target updated', 'Payment API', 'Critical threshold ms:', '1000', '1500']);
    }

    public function test_a_role_change_shows_old_and_new_role(): void
    {
        AuditLog::record('member.role_change', $this->admin, $this->team, ['user_id' => 9, 'email' => 'karim@example.com', 'old' => 'viewer', 'new' => 'manager']);

        $this->page()->assertSeeInOrder(['Role changed', 'Team Platform', 'karim@example.com', 'Role:', 'viewer', 'manager']);
    }

    // ─── Filtres et pagination ──────────────────────────────────────────

    public function test_filter_by_action(): void
    {
        AuditLog::record('target.create', $this->admin, $this->target, ['name' => 'Payment API']);
        AuditLog::record('member.invite', $this->admin, $this->team, ['email' => 'sara@example.com']);

        $this->page(['action' => 'member.invite'])->assertSee('sara@example.com')->assertDontSee('Target created</span>', false);
    }

    public function test_filter_by_author(): void
    {
        $karim = User::factory()->create(['name' => 'Karim']);
        $this->team->members()->attach($karim, ['role' => TeamRole::Manager]);
        AuditLog::record('member.invite', $this->admin, $this->team, ['email' => 'by-wissal@example.com']);
        AuditLog::record('target.create', $karim, $this->target, ['name' => 'by-karim']);

        $this->page(['user' => $karim->id])->assertSee('by-karim')->assertDontSee('by-wissal@example.com');
    }

    public function test_filter_by_period_in_the_display_timezone(): void
    {
        $this->travelTo('2026-10-10 12:00:00');
        AuditLog::record('member.invite', $this->admin, $this->team, ['email' => 'old@example.com']);
        $this->travelTo('2026-10-14 22:30:00'); // 15/10 00:30 à Paris
        AuditLog::record('member.invite', $this->admin, $this->team, ['email' => 'night@example.com']);
        $this->travelTo('2026-10-17 12:00:00');
        AuditLog::record('member.invite', $this->admin, $this->team, ['email' => 'new@example.com']);

        $this->page(['from' => '2026-10-15', 'to' => '2026-10-16'])
            ->assertSee('night@example.com')->assertDontSee('old@example.com')->assertDontSee('new@example.com');
    }

    public function test_invalid_filters_are_rejected(): void
    {
        $this->page(['from' => '2026-10-16', 'to' => '2026-10-01'])->assertSessionHasErrors('to');
        $this->page(['action' => 'drop.table'])->assertSessionHasErrors('action');
    }

    public function test_entries_are_paginated_newest_first(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->travelTo(now()->addMinute());
            AuditLog::record('member.invite', $this->admin, $this->team, ['email' => "user{$i}@example.com"]);
        }

        $this->page()->assertSeeInOrder(['user30@example.com', 'user29@example.com'])->assertDontSee('user5@example.com');
        $this->page(['page' => 2])->assertSee('user5@example.com')->assertSee('user1@example.com');
    }

    // ─── Purge ──────────────────────────────────────────────────────────

    public function test_entries_older_than_one_year_are_purged(): void
    {
        $this->travelTo('2025-10-16 12:00:00');
        $old = AuditLog::record('member.invite', $this->admin, $this->team);
        $this->travelTo('2025-10-18 12:00:00');
        $recent = AuditLog::record('member.invite', $this->admin, $this->team);
        $this->travelTo('2026-10-17 12:00:00');

        $this->artisan('audit:purge')->expectsOutput('Deleted 1 audit log entries older than 365 days.')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_the_purge_runs_every_night(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'audit:purge'));

        $this->assertNotNull($event);
        $this->assertSame('30 3 * * *', $event->expression);
    }

    // ─── Migration : lignes existantes ──────────────────────────────────

    public function test_the_migration_fills_the_team_of_existing_entries(): void
    {
        $alert = Alert::factory()->for($this->target, 'target')->create();
        $rows = [
            ['subject_type' => MonitoringTarget::class, 'subject_id' => $this->target->id],
            ['subject_type' => Alert::class, 'subject_id' => $alert->id],
            ['subject_type' => Team::class, 'subject_id' => $this->team->id],
            ['subject_type' => MonitoringTarget::class, 'subject_id' => 999], // target supprimée : team de l'auteur
        ];
        foreach ($rows as $row) {
            DB::table('audit_logs')->insert([...$row, 'user_id' => $this->admin->id, 'action' => 'x', 'created_at' => now()]);
        }

        $migration = require database_path('migrations/2026_10_17_000002_add_team_id_to_audit_logs_table.php');
        $migration->down();
        $migration->up();

        $this->assertSame(array_fill(0, 4, $this->team->id), DB::table('audit_logs')->orderBy('id')->pluck('team_id')->all());
    }
}
