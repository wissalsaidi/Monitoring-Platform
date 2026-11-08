<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Mail\DirectorySyncBlockedMail;
use App\Models\AuditLog;
use App\Models\DirectoryGroupMapping;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\FakesOidcProvider;
use Tests\TestCase;

/**
 * Groupes de l'annuaire vers teams et rôles, appliqués à chaque connexion SSO (SCRUM-92).
 */
class DirectoryGroupSyncTest extends TestCase
{
    use FakesOidcProvider, RefreshDatabase;

    private Team $payments;

    private Team $data;

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Mail::fake();
        $this->setUpOidcProvider();
        $this->payments = Team::factory()->create(['name' => 'Payments']);
        $this->data = Team::factory()->create(['name' => 'Data']);
        // Un autre Admin dans chaque team : la synchronisation peut retirer Alice sans vider la team
        foreach ([$this->payments, $this->data] as $team) {
            $team->members()->attach(User::factory()->create(), ['role' => TeamRole::Admin]);
        }
        $this->alice = User::factory()->create([
            'email' => 'alice@company.com', 'oidc_issuer' => self::ISSUER, 'oidc_subject' => 'subject-alice',
        ]);
    }

    private function map(Team $team, string $group, TeamRole $role): DirectoryGroupMapping
    {
        return DirectoryGroupMapping::create(['team_id' => $team->id, 'group' => $group, 'role' => $role]);
    }

    /** Connexion SSO d'Alice avec ces groupes, puis déconnexion pour la suivante */
    private function signInWithGroups(?array $groups): void
    {
        $this->claims = $groups === null ? [] : ['groups' => $groups];
        $this->withoutClaims = $groups === null ? ['groups'] : [];
        $this->signIn()->assertRedirect();
        $this->assertAuthenticatedAs($this->alice);
        auth()->logout();
        $this->alice->refresh();
    }

    private function membership(Team $team): ?object
    {
        return $this->alice->teams()->whereKey($team->id)->first()?->pivot;
    }

    // ─── Désactivé par défaut ─────────────────────────────────────────────

    public function test_without_mappings_nothing_changes(): void
    {
        $this->payments->members()->attach($this->alice, ['role' => TeamRole::Viewer]);

        $this->signInWithGroups(['monitoring-payments-admins']);

        $this->assertSame(TeamRole::Viewer, $this->membership($this->payments)->role);
        $this->assertSame(0, AuditLog::where('details->via', 'sso_group')->count());
    }

    // ─── Ajout, rôle, retrait ─────────────────────────────────────────────

    public function test_a_matching_group_adds_the_person_to_the_team(): void
    {
        $this->map($this->payments, 'monitoring-payments', TeamRole::Manager);
        TeamInvitation::create(['team_id' => $this->payments->id, 'email' => 'alice@company.com', 'role' => TeamRole::Viewer, 'expires_at' => now()->addDay()]);

        $this->signInWithGroups(['other-group', 'monitoring-payments']);

        $membership = $this->membership($this->payments);
        $this->assertSame(TeamRole::Manager, $membership->role);
        $this->assertSame('monitoring-payments', $membership->directory_group);
        $this->assertNull($this->membership($this->data));
        $this->assertSame(0, TeamInvitation::count());
        $log = AuditLog::where('action', 'member.add')->sole();
        $this->assertNull($log->user_id);
        $this->assertSame(['user_id' => $this->alice->id, 'email' => 'alice@company.com', 'role' => 'manager', 'via' => 'sso_group', 'group' => 'monitoring-payments'], $log->details);
    }

    public function test_several_groups_for_one_team_give_the_highest_role(): void
    {
        $this->map($this->payments, 'payments-viewers', TeamRole::Viewer);
        $this->map($this->payments, 'payments-admins', TeamRole::Admin);
        $this->map($this->data, 'data-viewers', TeamRole::Viewer);

        $this->signInWithGroups(['payments-viewers', 'payments-admins', 'data-viewers']);

        $this->assertSame(TeamRole::Admin, $this->membership($this->payments)->role);
        $this->assertSame('payments-admins', $this->membership($this->payments)->directory_group);
        $this->assertSame(TeamRole::Viewer, $this->membership($this->data)->role);
    }

    public function test_the_role_follows_the_groups_and_the_membership_ends_with_them(): void
    {
        $this->map($this->payments, 'payments-admins', TeamRole::Admin);
        $this->map($this->payments, 'payments-viewers', TeamRole::Viewer);

        $this->signInWithGroups(['payments-admins']);
        $this->signInWithGroups(['payments-viewers']);

        $this->assertSame(TeamRole::Viewer, $this->membership($this->payments)->role);
        $this->assertSame('payments-viewers', $this->membership($this->payments)->directory_group);
        $this->assertSame(['old' => 'admin', 'new' => 'viewer', 'via' => 'sso_group', 'group' => 'payments-viewers'],
            array_intersect_key(AuditLog::where('action', 'member.role_change')->sole()->details, array_flip(['old', 'new', 'via', 'group'])));

        $this->signInWithGroups([]);

        $this->assertNull($this->membership($this->payments));
        $this->assertSame('sso_group', AuditLog::where('action', 'member.remove')->sole()->details['via']);
    }

    /** Trouvé pendant la vérification avec Keycloak : la dernière correspondance supprimée ne retirait plus rien */
    public function test_removing_the_last_mapping_ends_the_memberships_it_gave(): void
    {
        $mapping = $this->map($this->payments, 'payments-viewers', TeamRole::Viewer);
        $this->data->members()->attach($this->alice, ['role' => TeamRole::Manager]);
        $this->signInWithGroups(['payments-viewers']);

        $mapping->delete();
        $this->signInWithGroups(['payments-viewers']);

        $this->assertNull($this->membership($this->payments));
        $this->assertSame(TeamRole::Manager, $this->membership($this->data)->role);
    }

    public function test_memberships_added_by_hand_are_never_removed(): void
    {
        $this->map($this->payments, 'payments-admins', TeamRole::Admin);
        $this->data->members()->attach($this->alice, ['role' => TeamRole::Manager]);
        $this->payments->members()->attach($this->alice, ['role' => TeamRole::Viewer]);

        // Le groupe correspond : le rôle suit, l'appartenance reste « à la main »
        $this->signInWithGroups(['payments-admins']);
        $this->assertSame(TeamRole::Admin, $this->membership($this->payments)->role);
        $this->assertNull($this->membership($this->payments)->directory_group);

        $this->signInWithGroups([]);

        $this->assertNotNull($this->membership($this->payments));
        $this->assertSame(TeamRole::Manager, $this->membership($this->data)->role);
        $this->assertSame(0, AuditLog::where('action', 'member.remove')->count());
    }

    public function test_a_token_without_groups_claim_changes_nothing(): void
    {
        $this->map($this->payments, 'payments-admins', TeamRole::Admin);
        $this->signInWithGroups(['payments-admins']);

        $this->signInWithGroups(null);

        $this->assertSame(TeamRole::Admin, $this->membership($this->payments)->role);
    }

    public function test_keycloak_group_paths_and_a_custom_claim_are_supported(): void
    {
        config(['oidc.groups_claim' => 'roles']);
        $this->map($this->payments, 'payments-admins', TeamRole::Manager);

        $this->claims = ['roles' => ['/payments-admins', 42, ''], 'groups' => ['ignored']];
        $this->signIn();

        $this->assertSame(TeamRole::Manager, $this->membership($this->payments)->role);
    }

    // ─── Garde-fous ───────────────────────────────────────────────────────

    public function test_a_group_never_grants_super_admin(): void
    {
        $this->map($this->payments, 'it-admins', TeamRole::Admin);

        $this->signInWithGroups(['it-admins', 'super-admins', 'platform-admins']);

        $this->assertFalse($this->alice->isSuperAdmin());
    }

    public function test_a_team_never_loses_its_last_active_admin(): void
    {
        $admin = User::factory()->superAdmin()->create();
        User::factory()->superAdmin()->create(['deactivated_at' => now()]);
        $solo = Team::factory()->create(['name' => 'Solo']);
        $this->map($solo, 'solo-admins', TeamRole::Admin);
        $this->signInWithGroups(['solo-admins']);

        $this->signInWithGroups([]);
        $this->signInWithGroups([]);

        $this->assertSame(TeamRole::Admin, $this->membership($solo)->role);
        $this->assertSame(2, AuditLog::where('action', 'member.sync_blocked')->count());
        $this->assertSame('solo-admins', AuditLog::where('action', 'member.sync_blocked')->first()->details['group']);
        Mail::assertQueued(DirectorySyncBlockedMail::class, 1);
        Mail::assertQueued(DirectorySyncBlockedMail::class, fn ($mail) => $mail->hasTo($admin->email) && $mail->team->is($solo));
    }

    // ─── Console d'administration ─────────────────────────────────────────

    public function test_a_super_admin_maps_and_unmaps_directory_groups(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->post(route('admin.teams.groups.store', $this->payments), ['group' => ' payments-admins ', 'role' => 'admin'])->assertSessionHas('success');
        $this->post(route('admin.teams.groups.store', $this->payments), ['group' => 'payments-admins', 'role' => 'viewer'])
            ->assertSessionHasErrors('group', null, 'directoryGroup');
        $this->post(route('admin.teams.groups.store', $this->payments), ['group' => 'x', 'role' => 'super_admin'])
            ->assertSessionHasErrors('role', null, 'directoryGroup');

        $mapping = DirectoryGroupMapping::sole();
        $this->assertSame(['payments-admins', TeamRole::Admin], [$mapping->group, $mapping->role]);
        $this->get(route('admin.teams.show', $this->payments))->assertOk()->assertSee('Directory groups')->assertSee('payments-admins');

        $this->delete(route('admin.teams.groups.destroy', [$this->data, $mapping]))->assertNotFound();
        $this->delete(route('admin.teams.groups.destroy', [$this->payments, $mapping]))->assertSessionHas('success');
        $this->assertModelMissing($mapping);
        $this->assertSame(['team.group_map', 'team.group_unmap'], AuditLog::whereIn('action', ['team.group_map', 'team.group_unmap'])->orderBy('id')->pluck('action')->all());
    }

    public function test_only_super_admins_manage_directory_groups(): void
    {
        $this->actingAs(User::factory()->withTeam(TeamRole::Admin)->create());

        $this->post(route('admin.teams.groups.store', $this->payments), ['group' => 'g', 'role' => 'admin'])->assertForbidden();
        $this->assertSame(0, DirectoryGroupMapping::count());
    }

    public function test_the_origin_of_a_membership_is_shown(): void
    {
        $this->map($this->payments, 'payments-admins', TeamRole::Manager);
        $this->signInWithGroups(['payments-admins']);
        $this->actingAs(User::factory()->superAdmin()->create());

        $this->get(route('admin.users.show', $this->alice))->assertOk()->assertSee('from directory group payments-admins');
        $this->get(route('admin.teams.show', $this->payments))->assertSee('from directory group payments-admins');
        $this->get(route('admin.audit'))->assertOk()->assertSee('Directory');
    }

    public function test_mappings_are_deleted_with_the_team(): void
    {
        $this->map($this->payments, 'payments-admins', TeamRole::Manager);

        $this->payments->delete();

        $this->assertSame(0, DirectoryGroupMapping::count());
    }
}
