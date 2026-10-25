<?php

namespace Tests\Feature;

use App\Enums\Permission;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

/**
 * RBAC (SCRUM-58) : toute la matrice rôle × permission, puis rôle × action HTTP.
 * La matrice attendue est écrite ici en clair : un changement de TeamRole::permissions() doit faire échouer ce test.
 */
class RbacTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    /** Permissions attendues par rôle (cahier des charges §26-27) */
    private const MATRIX = [
        'admin' => ['target.view', 'target.create', 'target.update', 'target.delete', 'alert.view', 'alert.acknowledge',
            'user.view', 'user.create', 'user.update', 'user.delete', 'dashboard.view', 'configuration.manage', 'audit.view', 'notification.manage'],
        'manager' => ['target.view', 'target.create', 'target.update', 'target.delete', 'alert.view', 'alert.acknowledge', 'dashboard.view',
            'notification.manage'],
        'viewer' => ['target.view', 'alert.view', 'dashboard.view'],
    ];

    private Team $team;

    private MonitoringTarget $target;

    private Alert $alert;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeZabbixApi();
        $this->team = Team::factory()->create();
        $this->target = MonitoringTarget::factory()->for($this->team)->create([
            'name' => 'Payment API',
            'url' => 'http://fake-api:8080/health',
            'zabbix_host_id' => '10684',
        ]);
        $this->alert = Alert::factory()->for($this->target, 'target')->create(['zabbix_event_id' => '9001']);
    }

    private function member(?TeamRole $role): User
    {
        $user = User::factory()->create();

        if ($role !== null) {
            $this->team->members()->attach($user, ['role' => $role]);
        } else {
            // Membre d'une autre team : aucun droit sur celle-ci
            Team::factory()->create()->members()->attach($user, ['role' => TeamRole::Admin]);
        }

        return $user;
    }

    // ─── Matrice rôle × permission ─────────────────────────────────────

    public static function rolePermissionPairs(): array
    {
        $pairs = [];
        foreach (TeamRole::cases() as $role) {
            foreach (Permission::cases() as $permission) {
                $pairs["{$role->value} / {$permission->value}"] = [$role, $permission, in_array($permission->value, self::MATRIX[$role->value], true)];
            }
        }

        return $pairs;
    }

    #[DataProvider('rolePermissionPairs')]
    public function test_the_role_permission_matrix(TeamRole $role, Permission $permission, bool $expected): void
    {
        $user = $this->member($role);

        $this->assertSame($expected, $role->can($permission));
        $this->assertSame($expected, $user->hasPermission($permission, $this->team));
        $this->assertSame($expected, Gate::forUser($user)->allows($permission->value, $this->team), 'ability '.$permission->value);
    }

    public function test_roles_are_nested_viewer_manager_admin(): void
    {
        $values = fn (TeamRole $role) => array_map(fn (Permission $p) => $p->value, $role->permissions());

        $this->assertEmpty(array_diff($values(TeamRole::Viewer), $values(TeamRole::Manager)));
        $this->assertEmpty(array_diff($values(TeamRole::Manager), $values(TeamRole::Admin)));
    }

    public function test_a_user_outside_the_team_has_no_permission(): void
    {
        $outsider = $this->member(null);

        foreach (Permission::cases() as $permission) {
            $this->assertFalse($outsider->hasPermission($permission, $this->team), $permission->value);
        }
    }

    // ─── Matrice rôle × action HTTP ────────────────────────────────────

    public static function roles(): array
    {
        return [
            'admin' => [TeamRole::Admin, true],
            'manager' => [TeamRole::Manager, true],
            'viewer' => [TeamRole::Viewer, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_every_role_can_read(TeamRole $role): void
    {
        $user = $this->member($role);

        $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('Payment API');
        $this->actingAs($user)->get(route('targets.index'))->assertOk()->assertSee('Payment API');
        $this->actingAs($user)->get(route('targets.show', $this->target))->assertOk();
        $this->actingAs($user)->get(route('alerts.index'))->assertOk()->assertSee('Payment API');
    }

    #[DataProvider('roles')]
    public function test_create_target(TeamRole $role, bool $allowed): void
    {
        $user = $this->member($role);

        $this->actingAs($user)->get(route('targets.create'))->assertStatus($allowed ? 200 : 403);
        $response = $this->actingAs($user)->post(route('targets.store'), [
            'type' => 'api', 'name' => 'Orders API', 'url' => 'https://api.example.com/health',
            'check_interval' => 30, 'warning_threshold_ms' => 500, 'critical_threshold_ms' => 1000,
        ]);

        $allowed ? $response->assertSessionHasNoErrors()->assertRedirect() : $response->assertForbidden();
        $this->assertSame($allowed, MonitoringTarget::where('name', 'Orders API')->exists());
    }

    #[DataProvider('roles')]
    public function test_update_target(TeamRole $role, bool $allowed): void
    {
        $user = $this->member($role);

        $this->actingAs($user)->get(route('targets.edit', $this->target))->assertStatus($allowed ? 200 : 403);
        $response = $this->actingAs($user)->put(route('targets.update', $this->target), [
            'name' => 'Payment API v2', 'url' => 'http://fake-api:8080/health',
            'check_interval' => 30, 'warning_threshold_ms' => 500, 'critical_threshold_ms' => 1000,
        ]);

        $allowed ? $response->assertSessionHasNoErrors()->assertRedirect() : $response->assertForbidden();
        $this->assertSame($allowed ? 'Payment API v2' : 'Payment API', $this->target->fresh()->name);
    }

    #[DataProvider('roles')]
    public function test_delete_target(TeamRole $role, bool $allowed): void
    {
        $response = $this->actingAs($this->member($role))->delete(route('targets.destroy', $this->target));

        $allowed ? $response->assertRedirect(route('targets.index')) : $response->assertForbidden();
        $this->assertSame(! $allowed, MonitoringTarget::whereKey($this->target->id)->exists());
    }

    #[DataProvider('roles')]
    public function test_acknowledge_alert(TeamRole $role, bool $allowed): void
    {
        $response = $this->actingAs($this->member($role))->post(route('alerts.acknowledge', $this->alert));

        $allowed ? $response->assertRedirect() : $response->assertForbidden();
        $this->assertSame($allowed, $this->alert->fresh()->acknowledged_at !== null);
    }

    #[DataProvider('roles')]
    public function test_buttons_follow_the_permissions(TeamRole $role, bool $allowed): void
    {
        $user = $this->member($role);
        $see = $allowed ? 'assertSee' : 'assertDontSee';

        $this->actingAs($user)->get(route('dashboard'))->{$see}('Add Monitoring Target');
        $this->actingAs($user)->get(route('targets.index'))->{$see}('Add Monitoring Target');
        $this->actingAs($user)->get(route('targets.show', $this->target))
            ->{$see}(route('targets.edit', $this->target))
            ->{$see}('data-bs-target="#delete-target"', false);
        $this->actingAs($user)->get(route('alerts.index'))->{$see}(route('alerts.acknowledge', $this->alert));
    }

    public function test_a_user_outside_the_team_cannot_reach_anything(): void
    {
        $outsider = $this->member(null);

        $this->actingAs($outsider)->get(route('targets.index'))->assertOk()->assertDontSee('Payment API');
        $this->actingAs($outsider)->get(route('alerts.index'))->assertOk()->assertDontSee('Payment API');
        $this->actingAs($outsider)->get(route('targets.show', $this->target))->assertForbidden();
        $this->actingAs($outsider)->get(route('targets.edit', $this->target))->assertForbidden();
        $this->actingAs($outsider)->delete(route('targets.destroy', $this->target))->assertForbidden();
        $this->actingAs($outsider)->post(route('alerts.acknowledge', $this->alert))->assertForbidden();
    }
}
