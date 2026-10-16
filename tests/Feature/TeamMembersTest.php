<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Mail\TeamInvitationMail;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Gestion des membres d'une team (SCRUM-59).
 */
class TeamMembersTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Mail::fake();
        $this->team = Team::factory()->create(['name' => 'Platform']);
        $this->admin = $this->member(TeamRole::Admin, 'Wissal', 'wissal@example.com');
    }

    private function member(TeamRole $role, string $name = 'Member', ?string $email = null): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email ?? fake()->unique()->safeEmail()]);
        $this->team->members()->attach($user, ['role' => $role]);

        return $user;
    }

    private function invite(string $email = 'sara@example.com', string $role = 'manager', ?User $by = null)
    {
        return $this->actingAs($by ?? $this->admin)
            ->from(route('teams.members.index', $this->team))
            ->post(route('teams.invitations.store', $this->team), ['email' => $email, 'role' => $role]);
    }

    private function roleOf(User $user): ?TeamRole
    {
        return $this->team->members()->whereKey($user->id)->first()?->pivot->role;
    }

    // ─── Page et accès ──────────────────────────────────────────────────

    public function test_an_admin_sees_the_members_and_the_invite_form(): void
    {
        $this->member(TeamRole::Viewer, 'Karim');

        $this->actingAs($this->admin)->get(route('teams.members.index', $this->team))
            ->assertOk()
            ->assertSee('Wissal')
            ->assertSee('Karim')
            ->assertSee('Send invitation');
    }

    public function test_the_menu_link_opens_the_members_page_of_the_admin_team(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))->assertSee(route('teams.current'));
        $this->actingAs($this->admin)->get(route('teams.current'))->assertRedirect(route('teams.members.index', $this->team));
    }

    public static function nonAdminRoles(): array
    {
        return ['manager' => [TeamRole::Manager], 'viewer' => [TeamRole::Viewer]];
    }

    #[DataProvider('nonAdminRoles')]
    public function test_only_admins_manage_members(TeamRole $role): void
    {
        $user = $this->member($role);
        $other = $this->member(TeamRole::Viewer);

        $this->actingAs($user)->get(route('dashboard'))->assertDontSee(route('teams.current'));
        $this->actingAs($user)->get(route('teams.current'))->assertForbidden();
        $this->actingAs($user)->get(route('teams.members.index', $this->team))->assertForbidden();
        $this->invite(by: $user)->assertForbidden();
        $this->actingAs($user)->patch(route('teams.members.update', [$this->team, $other]), ['role' => 'admin'])->assertForbidden();
        $this->actingAs($user)->delete(route('teams.members.destroy', [$this->team, $other]))->assertForbidden();

        $this->assertSame(TeamRole::Viewer, $this->roleOf($other));
        Mail::assertNothingQueued();
    }

    public function test_an_admin_of_another_team_cannot_manage_this_one(): void
    {
        $outsider = User::factory()->create();
        Team::factory()->create()->members()->attach($outsider, ['role' => TeamRole::Admin]);

        $this->actingAs($outsider)->get(route('teams.members.index', $this->team))->assertForbidden();
        $this->invite(by: $outsider)->assertForbidden();
    }

    // ─── Invitation ─────────────────────────────────────────────────────

    public function test_an_admin_invites_someone_by_email_with_a_role(): void
    {
        $this->invite('Sara@Example.com ', 'manager')
            ->assertRedirect(route('teams.members.index', $this->team))
            ->assertSessionHas('success', 'Invitation sent to sara@example.com.');

        $invitation = TeamInvitation::sole();
        $this->assertSame('sara@example.com', $invitation->email);
        $this->assertSame(TeamRole::Manager, $invitation->role);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $invitation->expires_at->timestamp, 5);

        Mail::assertQueued(TeamInvitationMail::class, fn ($mail) => $mail->hasTo('sara@example.com'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.invite', 'user_id' => $this->admin->id, 'subject_id' => $this->team->id]);
        $this->assertSame(['email' => 'sara@example.com', 'role' => 'manager', 'resent' => false], AuditLog::sole()->details);
    }

    public function test_the_email_contains_a_signed_link_valid_7_days(): void
    {
        $this->invite();
        $invitation = TeamInvitation::sole();

        $html = (new TeamInvitationMail($invitation))->render();

        $this->assertStringContainsString('Platform', $html);
        $this->assertStringContainsString('Manager', $html);
        $this->assertMatchesRegularExpression('#/invitations/'.$invitation->id.'/accept\?expires=\d+&amp;signature=[0-9a-f]{64}#', $html);
    }

    public function test_inviting_again_resends_and_replaces_the_pending_invitation(): void
    {
        $this->invite('sara@example.com', 'viewer');
        $this->travel(3)->days();
        $this->invite('sara@example.com', 'admin');

        $invitation = TeamInvitation::sole();
        $this->assertSame(TeamRole::Admin, $invitation->role);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $invitation->expires_at->timestamp, 5);
        Mail::assertQueuedCount(2);
        $this->assertTrue(AuditLog::latest('id')->first()->details['resent']);
    }

    public function test_an_existing_member_cannot_be_invited(): void
    {
        $this->invite('wissal@example.com')->assertSessionHasErrors(['email' => 'This person is already a member of the team.']);

        $this->assertSame(0, TeamInvitation::count());
        Mail::assertNothingQueued();
    }

    public function test_invitation_input_is_validated(): void
    {
        $this->invite('not-an-email', 'manager')->assertSessionHasErrors('email');
        $this->invite('sara@example.com', 'owner')->assertSessionHasErrors('role');

        $this->assertSame(0, TeamInvitation::count());
    }

    public function test_an_admin_cancels_a_pending_invitation(): void
    {
        $this->invite();
        $invitation = TeamInvitation::sole();

        $this->actingAs($this->admin)->delete(route('teams.invitations.destroy', [$this->team, $invitation]))
            ->assertSessionHas('success', 'Invitation to sara@example.com cancelled.');

        $this->assertSame(0, TeamInvitation::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.invite_cancel']);
    }

    // ─── Acceptation ────────────────────────────────────────────────────

    private function invitationFor(string $email, TeamRole $role = TeamRole::Manager): TeamInvitation
    {
        return TeamInvitation::create([
            'team_id' => $this->team->id, 'email' => $email, 'role' => $role,
            'invited_by' => $this->admin->id, 'expires_at' => now()->addDays(7),
        ]);
    }

    public function test_the_invited_person_joins_the_team_with_the_role(): void
    {
        $sara = User::factory()->create(['email' => 'sara@example.com']);
        $invitation = $this->invitationFor('sara@example.com');

        $this->actingAs($sara)->get($invitation->acceptUrl())
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', 'You joined the Platform team as Manager.');

        $this->assertSame(TeamRole::Manager, $this->roleOf($sara));
        $this->assertSame(0, TeamInvitation::count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.join', 'user_id' => $sara->id]);
    }

    public function test_a_guest_logs_in_first_then_comes_back_to_the_invitation(): void
    {
        $invitation = $this->invitationFor('sara@example.com');
        $url = $invitation->acceptUrl();

        $this->get($url)->assertRedirect(route('login'));
        $this->assertSame($url, session('url.intended'));
    }

    public function test_the_link_only_works_for_the_invited_email(): void
    {
        $other = User::factory()->create(['email' => 'other@example.com']);
        $invitation = $this->invitationFor('sara@example.com');

        $this->actingAs($other)->get($invitation->acceptUrl())
            ->assertOk()
            ->assertSee('This invitation was sent to sara@example.com');

        $this->assertNull($this->roleOf($other));
        $this->assertSame(1, TeamInvitation::count());
    }

    public function test_a_tampered_or_expired_link_is_refused(): void
    {
        $sara = User::factory()->create(['email' => 'sara@example.com']);
        $invitation = $this->invitationFor('sara@example.com');

        $tampered = str_replace('signature=', 'signature=0', $invitation->acceptUrl());
        $this->actingAs($sara)->get($tampered)->assertOk()->assertSee('no longer valid');

        $url = $invitation->acceptUrl();
        $this->travel(8)->days();
        $this->actingAs($sara)->get($url)->assertOk()->assertSee('no longer valid');

        $this->assertNull($this->roleOf($sara));
    }

    public function test_a_used_or_cancelled_link_is_refused(): void
    {
        $sara = User::factory()->create(['email' => 'sara@example.com']);
        $url = URL::temporarySignedRoute('invitations.accept', now()->addDay(), ['invitation' => 999]);

        $this->actingAs($sara)->get($url)->assertOk()->assertSee('no longer valid');
    }

    // ─── Rôles et retrait ───────────────────────────────────────────────

    public function test_an_admin_changes_the_role_of_a_member(): void
    {
        $karim = $this->member(TeamRole::Viewer, 'Karim');

        $this->actingAs($this->admin)->from(route('teams.members.index', $this->team))
            ->patch(route('teams.members.update', [$this->team, $karim]), ['role' => 'manager'])
            ->assertSessionHas('success', 'Karim is now Manager.');

        $this->assertSame(TeamRole::Manager, $this->roleOf($karim));
        $log = AuditLog::where('action', 'member.role_change')->sole();
        $this->assertSame(['user_id' => $karim->id, 'email' => $karim->email, 'old' => 'viewer', 'new' => 'manager'], $log->details);
    }

    public function test_an_admin_removes_a_member(): void
    {
        $karim = $this->member(TeamRole::Manager, 'Karim');

        $this->actingAs($this->admin)->from(route('teams.members.index', $this->team))
            ->delete(route('teams.members.destroy', [$this->team, $karim]))
            ->assertSessionHas('success', 'Karim was removed from the team.');

        $this->assertNull($this->roleOf($karim));
        $this->assertDatabaseHas('users', ['id' => $karim->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'member.remove']);
        $this->actingAs($karim)->get(route('targets.index'))->assertOk();
    }

    public function test_the_last_admin_cannot_be_demoted_or_removed(): void
    {
        $this->actingAs($this->admin)->from(route('teams.members.index', $this->team))
            ->patch(route('teams.members.update', [$this->team, $this->admin]), ['role' => 'viewer'])
            ->assertSessionHas('error', 'A team must keep at least one Admin. Give the Admin role to another member first.');

        $this->actingAs($this->admin)->from(route('teams.members.index', $this->team))
            ->delete(route('teams.members.destroy', [$this->team, $this->admin]))
            ->assertSessionHas('error');

        $this->assertSame(TeamRole::Admin, $this->roleOf($this->admin));
        $this->assertSame(0, AuditLog::count());
    }

    public function test_with_two_admins_one_can_leave(): void
    {
        $this->member(TeamRole::Admin, 'Karim');

        $this->actingAs($this->admin)->delete(route('teams.members.destroy', [$this->team, $this->admin]))
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', 'You left the Platform team.');

        $this->assertNull($this->roleOf($this->admin));
    }

    public function test_the_last_admin_controls_are_disabled_on_the_page(): void
    {
        $this->actingAs($this->admin)->get(route('teams.members.index', $this->team))
            ->assertSee('title="A team must keep at least one Admin"', false)
            ->assertDontSee('Leave');
    }

    public function test_a_user_outside_the_team_cannot_be_changed_through_this_team(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($this->admin)->from(route('teams.members.index', $this->team))
            ->patch(route('teams.members.update', [$this->team, $stranger]), ['role' => 'admin'])
            ->assertSessionHas('error', 'This person is not a member of the team.');

        $this->assertNull($this->roleOf($stranger));
    }
}
