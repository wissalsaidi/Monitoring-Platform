<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\PlatformPermission;
use App\Enums\PlatformRole;
use App\Enums\TeamRole;
use App\Exceptions\PlatformAdminException;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use App\Services\PlatformAdminService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Super Admin de la plateforme (SCRUM-81) : rôle, permissions, commandes et garde-fous.
 */
class PlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Correct-Horse-Battery-9';

    public function test_existing_users_have_no_platform_role(): void
    {
        $user = User::factory()->create();

        $this->assertNull($user->fresh()->platform_role);
        $this->assertFalse($user->isSuperAdmin());
    }

    public function test_the_platform_role_cannot_be_mass_assigned(): void
    {
        $user = User::create(['name' => 'Eve', 'email' => 'eve@example.com', 'password' => 'x', 'platform_role' => 'super_admin']);

        $this->assertNull($user->fresh()->platform_role);
    }

    public function test_a_super_admin_has_every_platform_permission_and_the_gates(): void
    {
        $admin = User::factory()->superAdmin()->create();
        $user = User::factory()->create();

        foreach (PlatformPermission::cases() as $permission) {
            $this->assertTrue($admin->hasPlatformPermission($permission));
            $this->assertTrue(Gate::forUser($admin)->allows($permission->value), $permission->value);
            $this->assertFalse($user->hasPlatformPermission($permission));
            $this->assertFalse(Gate::forUser($user)->allows($permission->value), $permission->value);
        }
        $this->assertTrue(Gate::forUser($admin)->allows('isSuperAdmin'));
        $this->assertFalse(Gate::forUser($user)->allows('isSuperAdmin'));
        $this->assertSame(['platform.users.manage', 'platform.teams.manage', 'platform.audit.view'],
            array_map(fn (PlatformPermission $p) => $p->value, PlatformRole::SuperAdmin->permissions()));
    }

    public function test_a_super_admin_has_no_access_to_a_team_they_do_not_belong_to(): void
    {
        $admin = User::factory()->superAdmin()->withTeam()->create();   // admin de sa propre team, pas de celle-ci
        $team = Team::factory()->create();

        foreach (Permission::cases() as $permission) {
            $this->assertFalse($admin->hasPermission($permission, $team), $permission->value);
        }
        $this->actingAs($admin)->get(route('teams.notifications.index', $team))->assertForbidden();
        $this->actingAs($admin)->get(route('teams.audit.index', $team))->assertForbidden();
    }

    public function test_create_admin_creates_a_verified_account_without_team_and_records_it(): void
    {
        $this->artisan('platform:create-admin', ['email' => 'IT.Admin@Company.com'])
            ->expectsQuestion('Name', 'IT Admin')
            ->expectsQuestion('Password (hidden)', self::PASSWORD)
            ->expectsQuestion('Confirm the password', self::PASSWORD)
            ->expectsOutputToContain('Super Admin it.admin@company.com created')
            ->assertSuccessful();

        $admin = User::where('email', 'it.admin@company.com')->sole();
        $this->assertTrue($admin->isSuperAdmin());
        $this->assertNotNull($admin->email_verified_at);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
        $this->assertCount(0, $admin->teams);                   // aucune team créée
        $this->assertSame(0, Team::count());

        $this->assertSame(['user.create', 'platform.admin_grant'], AuditLog::orderBy('id')->pluck('action')->all());
        $grant = AuditLog::where('action', 'platform.admin_grant')->sole();
        $this->assertNull($grant->user_id);
        $this->assertNull($grant->team_id);
        $this->assertSame(['email' => 'it.admin@company.com', 'via' => 'console'], $grant->details);
    }

    public function test_create_admin_refuses_a_weak_or_mismatched_password(): void
    {
        $this->artisan('platform:create-admin', ['email' => 'admin@company.com'])
            ->expectsQuestion('Name', 'IT Admin')
            ->expectsQuestion('Password (hidden)', self::PASSWORD)
            ->expectsQuestion('Confirm the password', 'something-else')
            ->assertFailed();
        $this->artisan('platform:create-admin', ['email' => 'admin@company.com'])
            ->expectsQuestion('Name', 'IT Admin')
            ->expectsQuestion('Password (hidden)', 'short')
            ->expectsQuestion('Confirm the password', 'short')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_a_new_account_needs_an_interactive_password(): void
    {
        $this->artisan('platform:create-admin', ['email' => 'admin@company.com', '--no-interaction' => true])
            ->expectsOutputToContain('typed interactively')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_create_admin_refuses_an_invalid_email(): void
    {
        $this->artisan('platform:create-admin', ['email' => 'not-an-email'])->assertFailed();
    }

    public function test_create_admin_promotes_an_existing_account_and_keeps_its_teams(): void
    {
        $user = User::factory()->create(['email' => 'demo@example.com']);
        $team = Team::factory()->create();
        $team->members()->attach($user, ['role' => TeamRole::Admin]);

        $this->artisan('platform:create-admin', ['email' => 'demo@example.com'])
            ->expectsConfirmation('Promote the existing account '.$user->name.' <demo@example.com> to Super Admin?', 'yes')
            ->expectsOutputToContain('is now a Super Admin')
            ->assertSuccessful();

        $this->assertTrue($user->fresh()->isSuperAdmin());
        $this->assertSame(TeamRole::Admin, $user->fresh()->roleIn($team));
        $this->assertSame('platform.admin_grant', AuditLog::sole()->action);
    }

    public function test_promotion_can_be_declined_and_is_idempotent(): void
    {
        $user = User::factory()->create(['email' => 'demo@example.com']);

        $this->artisan('platform:create-admin', ['email' => 'demo@example.com'])
            ->expectsConfirmation('Promote the existing account '.$user->name.' <demo@example.com> to Super Admin?', 'no')
            ->assertFailed();
        $this->assertFalse($user->fresh()->isSuperAdmin());

        $user->forceFill(['platform_role' => PlatformRole::SuperAdmin])->save();
        $this->artisan('platform:create-admin', ['email' => 'demo@example.com'])
            ->expectsOutputToContain('already a Super Admin')
            ->assertSuccessful();
        $this->assertCount(0, AuditLog::all());
    }

    public function test_revoke_admin_keeps_the_account_and_records_it(): void
    {
        User::factory()->superAdmin()->create(['email' => 'first@company.com']);
        $second = User::factory()->superAdmin()->create(['email' => 'second@company.com']);

        $this->artisan('platform:revoke-admin', ['email' => 'second@company.com'])->assertSuccessful();

        $this->assertFalse($second->fresh()->isSuperAdmin());
        $log = AuditLog::sole();
        $this->assertSame('platform.admin_revoke', $log->action);
        $this->assertSame($second->id, $log->subject_id);
    }

    public function test_the_last_super_admin_cannot_be_revoked(): void
    {
        $only = User::factory()->superAdmin()->create(['email' => 'only@company.com']);

        $this->artisan('platform:revoke-admin', ['email' => 'only@company.com'])
            ->expectsOutputToContain('at least one Super Admin')
            ->assertFailed();

        $this->assertTrue($only->fresh()->isSuperAdmin());
        $this->expectException(PlatformAdminException::class);
        app(PlatformAdminService::class)->revoke($only);
    }

    public function test_revoke_admin_refuses_an_unknown_account_or_a_non_admin(): void
    {
        User::factory()->superAdmin()->count(2)->create();
        User::factory()->create(['email' => 'user@company.com']);

        $this->artisan('platform:revoke-admin', ['email' => 'nobody@company.com'])->assertFailed();
        $this->artisan('platform:revoke-admin', ['email' => 'user@company.com'])
            ->expectsOutputToContain('is not a Super Admin')
            ->assertFailed();
    }

    public function test_list_admins(): void
    {
        $this->artisan('platform:list-admins')->expectsOutputToContain('No Super Admin yet')->assertSuccessful();

        User::factory()->superAdmin()->create(['name' => 'IT Admin', 'email' => 'it@company.com']);
        User::factory()->create(['email' => 'user@company.com']);

        $this->artisan('platform:list-admins')
            ->expectsOutputToContain('it@company.com')
            ->doesntExpectOutputToContain('user@company.com')
            ->assertSuccessful();
    }

    public function test_the_last_super_admin_cannot_delete_their_account(): void
    {
        $this->withoutVite();
        $admin = User::factory()->superAdmin()->create(['password' => self::PASSWORD]);

        $this->actingAs($admin)->get(route('account.edit'))
            ->assertSee('Super Admin')
            ->assertSee('You are the last Super Admin of the platform');
        $this->actingAs($admin)->delete(route('account.destroy'), ['password' => self::PASSWORD])
            ->assertSessionHas('error');

        $this->assertModelExists($admin);
    }

    public function test_a_super_admin_can_delete_their_account_when_another_remains(): void
    {
        $admin = User::factory()->superAdmin()->create(['password' => self::PASSWORD]);
        User::factory()->superAdmin()->create();

        $this->actingAs($admin)->delete(route('account.destroy'), ['password' => self::PASSWORD]);

        $this->assertModelMissing($admin);
    }

    public function test_the_audit_log_names_the_promoted_user(): void
    {
        $user = User::factory()->create(['name' => 'Wissal']);
        app(PlatformAdminService::class)->grant($user);

        $log = AuditLog::sole();
        $this->assertSame('Super Admin granted', $log->actionLabel());
        $this->assertSame('Wissal', $log->subjectLabel());
    }
}
