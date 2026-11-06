<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\NotificationEvent;
use App\Enums\TeamRole;
use App\Mail\TeamInvitationMail;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\GrafanaAccessService;
use App\Services\NotificationRouter;
use App\Services\PlatformAdminService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Console d'administration des utilisateurs (SCRUM-84) : liste, actions de team et de plateforme,
 * désactivation, réinitialisation forcée du mot de passe, garde-fous.
 */
class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng-password';

    private User $it;

    private Team $team;

    private User $sara;

    private MockInterface $grafana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Mail::fake();
        $this->grafana = $this->mock(GrafanaAccessService::class)->shouldIgnoreMissing();
        $this->it = User::factory()->superAdmin()->create(['name' => 'IT Admin', 'email' => 'it@company.com']);
        $this->team = Team::factory()->create(['name' => 'Payments']);
        $boss = User::factory()->create(['name' => 'Boss']);
        $this->team->members()->attach($boss, ['role' => TeamRole::Admin]);
        $this->sara = User::factory()->create(['name' => 'Sara', 'email' => 'sara@company.com', 'password' => self::PASSWORD]);
        $this->team->members()->attach($this->sara, ['role' => TeamRole::Manager]);
    }

    private function as(?User $user = null): static
    {
        return $this->actingAs($user ?? $this->it)->from(route('admin.users.show', $this->sara));
    }

    // ─── Accès ──────────────────────────────────────────────────────────

    public function test_only_super_admins_reach_the_console(): void
    {
        $teamAdmin = $this->team->members()->wherePivot('role', 'admin')->first();

        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
        $this->actingAs($teamAdmin)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($teamAdmin)->post(route('admin.users.deactivate', $this->sara))->assertForbidden();
        $this->actingAs($teamAdmin)->get('/')->assertDontSee('Administration');

        // Un Super Admin sans team y accède quand même
        $this->actingAs($this->it)->get(route('admin.users.index'))->assertOk();
        $this->actingAs($this->it)->get(route('access.waiting'))->assertSee('Open the administration console');
    }

    public function test_the_menu_shows_administration_to_a_super_admin(): void
    {
        $this->team->members()->attach($this->it, ['role' => TeamRole::Viewer]);

        $this->actingAs($this->it)->get('/')->assertSee(route('admin.users.index'));
    }

    // ─── Liste ──────────────────────────────────────────────────────────

    public function test_the_list_shows_teams_roles_status_and_last_login(): void
    {
        $this->sara->forceFill(['last_login_at' => '2026-11-05 08:30:00'])->save();
        User::factory()->create(['name' => 'Gone', 'deactivated_at' => now()]);

        $this->actingAs($this->it)->get(route('admin.users.index'))->assertOk()
            ->assertSeeInOrder(['Boss', 'Gone', 'IT Admin', 'Sara'])
            ->assertSee(['Payments · Manager', 'Super Admin', 'Deactivated', '05/11/2026 09:30', 'No team', 'Never']);
    }

    public function test_teams_with_the_same_name_are_told_apart(): void
    {
        $twin = Team::factory()->create(['name' => 'Payments']);
        $twin->members()->attach($this->sara, ['role' => TeamRole::Viewer]);

        $this->actingAs($this->it)->get(route('admin.users.index'))
            ->assertSee(["Payments #{$this->team->id} · Manager", "Payments #{$twin->id} · Viewer"]);
    }

    public function test_search_and_filters(): void
    {
        User::factory()->create(['name' => 'Gone', 'email' => 'gone@company.com', 'deactivated_at' => now()]);
        User::factory()->create(['name' => 'Outsider', 'email' => 'outsider@company.com']);   // sans team

        // (l'adresse de l'admin connecté apparaît dans le menu du compte : on vérifie sur d'autres comptes)
        $this->actingAs($this->it)->get(route('admin.users.index', ['q' => 'SARA@comp']))
            ->assertSee('sara@company.com')->assertDontSee('outsider@company.com');
        $this->actingAs($this->it)->get(route('admin.users.index', ['status' => 'deactivated']))
            ->assertSee('gone@company.com')->assertDontSee('sara@company.com');
        $this->actingAs($this->it)->get(route('admin.users.index', ['status' => 'active']))
            ->assertSee('sara@company.com')->assertDontSee('gone@company.com');
        $this->actingAs($this->it)->get(route('admin.users.index', ['team' => $this->team->id]))
            ->assertSee('sara@company.com')->assertDontSee('outsider@company.com');
        $this->actingAs($this->it)->get(route('admin.users.index', ['q' => '%']))->assertSee('No user matches');
        $this->actingAs($this->it)->get(route('admin.users.index', ['status' => 'owner']))->assertSessionHasErrors('status');
    }

    public function test_the_invited_tab_lists_invitations_without_account(): void
    {
        TeamInvitation::create(['team_id' => $this->team->id, 'email' => 'new@company.com', 'role' => TeamRole::Viewer, 'expires_at' => now()->addDays(7)]);
        TeamInvitation::create(['team_id' => $this->team->id, 'email' => 'it@company.com', 'role' => TeamRole::Viewer, 'expires_at' => now()->addDays(7)]);

        $this->actingAs($this->it)->get(route('admin.users.index', ['status' => 'invited']))->assertOk()
            ->assertSee(['new@company.com', 'Payments · Viewer', 'Console'])
            ->assertDontSee('it@company.com</td>', false);
    }

    public function test_the_list_is_paginated(): void
    {
        User::factory()->count(30)->create();

        $this->actingAs($this->it)->get(route('admin.users.index'))->assertSee('page=2', false);
    }

    // ─── Invitations et teams ───────────────────────────────────────────

    public function test_a_super_admin_invites_into_any_team(): void
    {
        $this->as()->post(route('admin.users.invite'), ['email' => 'New@Company.com', 'team_id' => $this->team->id, 'role' => 'admin'])
            ->assertSessionHas('success', 'Invitation sent to new@company.com for the Payments team.');

        $this->assertSame(TeamRole::Admin, TeamInvitation::where('email', 'new@company.com')->sole()->role);
        Mail::assertQueued(TeamInvitationMail::class);
        $this->as()->post(route('admin.users.invite'), ['email' => 'sara@company.com', 'team_id' => $this->team->id, 'role' => 'viewer'])
            ->assertSessionHasErrors(['email' => 'This person is already a member of the team.']);
    }

    public function test_add_change_role_and_remove_from_a_team(): void
    {
        $ops = Team::factory()->create(['name' => 'Ops']);
        TeamInvitation::create(['team_id' => $ops->id, 'email' => 'sara@company.com', 'role' => TeamRole::Viewer, 'expires_at' => now()->addDay()]);

        $this->as()->post(route('admin.users.teams.store', $this->sara), ['team_id' => $ops->id, 'role' => 'admin'])
            ->assertSessionHas('success', 'Sara was added to the Ops team as Admin.');
        $this->assertSame(TeamRole::Admin, $this->sara->roleIn($ops));
        $this->assertSame(0, TeamInvitation::count());                          // invitation devenue inutile
        $this->assertSame(['user_id' => $this->sara->id, 'email' => 'sara@company.com', 'role' => 'admin'],
            AuditLog::where('action', 'member.add')->sole()->details);

        $this->as()->post(route('admin.users.teams.store', $this->sara), ['team_id' => $ops->id, 'role' => 'viewer'])
            ->assertSessionHas('error', 'This person is already a member of the team.');

        $this->as()->patch(route('admin.users.teams.update', [$this->sara, $this->team]), ['role' => 'viewer'])
            ->assertSessionHas('success', 'Sara is now Viewer in Payments.');
        $this->assertSame(TeamRole::Viewer, $this->sara->fresh()->roleIn($this->team));

        $this->grafana->shouldReceive('revoke')->once();
        $this->as()->delete(route('admin.users.teams.destroy', [$this->sara, $this->team]))
            ->assertSessionHas('success', 'Sara was removed from the Payments team.');
        $this->assertFalse($this->sara->belongsToTeam($this->team));
        $this->assertSame($this->it->id, AuditLog::where('action', 'member.remove')->sole()->user_id);
    }

    public function test_a_team_never_loses_its_last_admin(): void
    {
        $boss = $this->team->members()->wherePivot('role', 'admin')->first();

        $this->as()->patch(route('admin.users.teams.update', [$boss, $this->team]), ['role' => 'viewer'])->assertSessionHas('error');
        $this->as()->delete(route('admin.users.teams.destroy', [$boss, $this->team]))->assertSessionHas('error');
        $this->actingAs($this->it)->get(route('admin.users.show', $boss))->assertSee('Only Admin of the team');

        $this->assertSame(TeamRole::Admin, $boss->fresh()->roleIn($this->team));
    }

    public function test_a_deactivated_admin_does_not_count_as_the_other_admin(): void
    {
        $boss = $this->team->members()->wherePivot('role', 'admin')->first();
        $old = User::factory()->create(['deactivated_at' => now()]);
        $this->team->members()->attach($old, ['role' => TeamRole::Admin]);

        $this->as()->delete(route('admin.users.teams.destroy', [$boss, $this->team]))->assertSessionHas('error');
        $this->assertTrue($boss->belongsToTeam($this->team));
    }

    // ─── Super Admin ────────────────────────────────────────────────────

    public function test_promote_and_demote_a_super_admin(): void
    {
        $this->as()->post(route('admin.users.super-admin.store', $this->sara))->assertSessionHas('success', 'Sara is now a Super Admin.');
        $this->assertTrue($this->sara->fresh()->isSuperAdmin());

        $this->as()->delete(route('admin.users.super-admin.destroy', $this->sara))->assertSessionHas('success');
        $this->assertFalse($this->sara->fresh()->isSuperAdmin());
        $this->assertSame(['platform.admin_grant', 'platform.admin_revoke'], AuditLog::orderBy('id')->pluck('action')->all());
        $this->assertSame($this->it->id, AuditLog::first()->user_id);
    }

    public function test_the_last_active_super_admin_keeps_the_role(): void
    {
        User::factory()->superAdmin()->create(['deactivated_at' => now()]);    // désactivé : ne compte pas

        $this->as()->delete(route('admin.users.super-admin.destroy', $this->it))
            ->assertSessionHas('error', 'The platform must keep at least one Super Admin. Promote someone else first.');
        $this->assertTrue($this->it->fresh()->isSuperAdmin());
        $this->assertTrue(app(PlatformAdminService::class)->isLastSuperAdmin($this->it));
    }

    public function test_a_deactivated_account_cannot_be_promoted(): void
    {
        $this->sara->forceFill(['deactivated_at' => now()])->save();

        $this->as()->post(route('admin.users.super-admin.store', $this->sara))
            ->assertSessionHas('error', 'Reactivate the account before making it a Super Admin.');
        $this->assertFalse($this->sara->fresh()->isSuperAdmin());
    }

    // ─── Désactivation ──────────────────────────────────────────────────

    public function test_deactivation_cuts_every_access_and_keeps_the_history(): void
    {
        $ops = Team::factory()->create();
        $ops->members()->attach($this->sara, ['role' => TeamRole::Viewer]);
        $token = $this->sara->remember_token;
        $this->grafana->shouldReceive('revoke')->twice();             // ses deux teams

        $this->as()->post(route('admin.users.deactivate', $this->sara))
            ->assertSessionHas('success', 'Sara was deactivated: sessions closed, Grafana access removed, no more notifications.');

        $sara = $this->sara->fresh();
        $this->assertFalse($sara->isActive());
        $this->assertNotSame($token, $sara->remember_token);
        $this->assertSame(TeamRole::Manager, $sara->roleIn($this->team));    // teams gardées
        $log = AuditLog::where('action', 'user.deactivate')->sole();
        $this->assertSame([$this->it->id, $sara->id, null], [$log->user_id, $log->subject_id, $log->team_id]);
        $this->assertSame('Account deactivated', $log->actionLabel());
    }

    public function test_a_deactivated_account_cannot_log_in(): void
    {
        $this->sara->forceFill(['deactivated_at' => now()])->save();

        $this->post('/login', ['email' => 'sara@company.com', 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => 'This account has been deactivated. Contact your administrator.']);
        $this->assertGuest();

        // Mauvais mot de passe : le message habituel, sans dire que le compte existe
        $this->post('/login', ['email' => 'sara@company.com', 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => trans('auth.failed')]);
    }

    public function test_an_open_session_is_closed_at_the_next_request(): void
    {
        $this->actingAs($this->sara)->get('/')->assertOk();
        $this->sara->forceFill(['deactivated_at' => now()])->save();

        $this->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_grafana_refuses_a_deactivated_account(): void
    {
        $this->sara->forceFill(['deactivated_at' => now(), 'grafana_user_id' => 42])->save();

        $this->actingAs($this->sara)->get(route('grafana.auth'))->assertStatus(401);
    }

    public function test_a_deactivated_account_gets_no_notification(): void
    {
        $target = MonitoringTarget::factory()->for($this->team)->create();
        $alert = Alert::factory()->for($target, 'target')->create(['severity' => AlertSeverity::Critical]);
        $router = app(NotificationRouter::class);
        $this->assertContains('sara@company.com', $router->recipients($alert, NotificationEvent::Opened)->pluck('email'));

        $this->sara->forceFill(['deactivated_at' => now()])->save();

        $this->assertNotContains('sara@company.com', $router->recipients($alert, NotificationEvent::Opened)->pluck('email'));
    }

    public function test_deactivation_safeguards(): void
    {
        $boss = $this->team->members()->wherePivot('role', 'admin')->first();

        $this->as()->post(route('admin.users.deactivate', $this->it))->assertSessionHas('error', 'You cannot deactivate your own account.');

        $other = User::factory()->superAdmin()->create(['name' => 'Other IT']);
        $this->it->forceFill(['deactivated_at' => now()])->save();      // il ne reste qu'un Super Admin actif
        $this->as($other)->post(route('admin.users.deactivate', $this->it->fresh()))->assertSessionHas('error');
        $this->it->forceFill(['deactivated_at' => null])->save();
        $this->it->forceFill(['platform_role' => null])->save();
        $this->as($other)->post(route('admin.users.deactivate', $other))->assertSessionHas('error', 'You cannot deactivate your own account.');

        $this->it->forceFill(['platform_role' => 'super_admin'])->save();
        $this->as($this->it)->post(route('admin.users.deactivate', $other))->assertSessionHas('success');
        $this->as($other->fresh())->post(route('admin.users.deactivate', $this->it))->assertRedirect(route('login'));   // désactivé : déconnecté

        $this->as($this->it)->post(route('admin.users.deactivate', $boss))
            ->assertSessionHas('error', 'Boss is the only Admin of Payments. Give the Admin role to another member first.');
        $this->assertTrue($boss->fresh()->isActive());
    }

    public function test_the_last_active_super_admin_cannot_be_deactivated(): void
    {
        $second = User::factory()->superAdmin()->create();
        $this->as($second)->post(route('admin.users.deactivate', $this->it))->assertSessionHas('success');

        $third = User::factory()->superAdmin()->create(['deactivated_at' => now()]);
        $this->assertFalse($third->isActive());
        $this->assertSame(1, User::superAdmins()->active()->count());
    }

    public function test_reactivation_restores_access(): void
    {
        $this->sara->forceFill(['deactivated_at' => now()])->save();
        $this->grafana->shouldReceive('syncUser')->once();

        $this->as()->post(route('admin.users.reactivate', $this->sara))->assertSessionHas('success', 'Sara was reactivated.');

        $this->assertTrue($this->sara->fresh()->isActive());
        $this->assertSame('user.reactivate', AuditLog::sole()->action);
        auth()->logout();
        $this->post('/login', ['email' => 'sara@company.com', 'password' => self::PASSWORD])->assertRedirect('/');
        $this->assertNotNull($this->sara->fresh()->last_login_at);

        $this->as()->post(route('admin.users.reactivate', $this->sara->fresh()))->assertSessionHas('error', 'sara@company.com is already active.');
    }

    // ─── Mot de passe ───────────────────────────────────────────────────

    public function test_forcing_a_password_reset(): void
    {
        Notification::fake();

        $this->as()->post(route('admin.users.password-reset', $this->sara))
            ->assertSessionHas('success', 'The password of Sara no longer works. A reset link was sent to sara@company.com.');

        $this->assertFalse(Hash::check(self::PASSWORD, $this->sara->fresh()->password));
        Notification::assertSentTo($this->sara, ResetPassword::class);
        $this->assertSame(['email' => 'sara@company.com'], AuditLog::where('action', 'user.password_reset_forced')->sole()->details);

        $this->as()->post(route('admin.users.password-reset', $this->it))->assertSessionHas('error');
    }

    // ─── Fiche ──────────────────────────────────────────────────────────

    public function test_the_user_page_shows_teams_actions_and_activity(): void
    {
        Team::factory()->create(['name' => 'Ops']);                       // une team dont elle n'est pas membre
        AuditLog::record('member.role_change', $this->it, $this->team, ['user_id' => $this->sara->id]);
        AuditLog::record('user.reactivate', $this->it, $this->sara);

        $this->actingAs($this->it)->get(route('admin.users.show', $this->sara))->assertOk()
            ->assertSee(['Sara', 'sara@company.com', 'Active', 'Payments', 'Make Super Admin', 'Force a password reset', 'Deactivate the account', 'Account reactivated'])
            ->assertSee('Add to team');

        // Sa propre fiche : ni désactivation ni mot de passe
        $this->actingAs($this->it)->get(route('admin.users.show', $this->it))->assertOk()
            ->assertDontSee('Deactivate the account')->assertDontSee('Force a password reset')
            ->assertSee('The last active Super Admin cannot lose the role');
    }
}
