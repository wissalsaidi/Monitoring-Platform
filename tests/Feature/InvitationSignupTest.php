<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Mail\NewAccountMail;
use App\Mail\TeamInvitationMail;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Le lien d'invitation, porte d'entrée de la plateforme (SCRUM-83) : création du compte pour une adresse inconnue,
 * invitation par un Super Admin vers n'importe quelle team.
 */
class InvitationSignupTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng-password';

    private Team $team;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Mail::fake();
        Notification::fake();
        config(['registration.mode' => 'invite']);       // l'inscription publique est fermée
        $this->team = Team::factory()->create(['name' => 'Payments']);
        $this->admin = User::factory()->create(['name' => 'Wissal']);
        $this->team->members()->attach($this->admin, ['role' => TeamRole::Admin]);
    }

    private function invitation(string $email = 'sara@company.com', TeamRole $role = TeamRole::Manager): TeamInvitation
    {
        return TeamInvitation::create([
            'team_id' => $this->team->id, 'email' => $email, 'role' => $role,
            'invited_by' => $this->admin->id, 'expires_at' => now()->addDays(TeamInvitation::VALID_DAYS),
        ]);
    }

    private function signUp(string $url, array $data = []): TestResponse
    {
        return $this->post($url, [
            'name' => 'Sara', 'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD, ...$data,
        ]);
    }

    // ─── Adresse inconnue : création du compte ──────────────────────────

    public function test_an_unknown_address_gets_the_create_account_page_with_a_locked_email(): void
    {
        $url = $this->invitation()->acceptUrl();

        $this->get($url)->assertOk()
            ->assertSee(['Create your account', 'Wissal invited you to join the', 'Payments', 'Manager'])
            ->assertSee('value="sara@company.com"', false)
            ->assertSee('readonly disabled', false)
            ->assertDontSee('name="email"', false)                  // l'adresse ne peut pas être envoyée par le formulaire
            ->assertSee('action="'.e($url).'"', false);
        $this->assertGuest();
    }

    public function test_the_account_is_created_verified_in_the_invited_team_only(): void
    {
        $url = $this->invitation()->acceptUrl();

        $this->signUp($url)
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('success', 'Welcome! You joined the Payments team as Manager.');

        $sara = User::where('email', 'sara@company.com')->sole();
        $this->assertAuthenticatedAs($sara);
        $this->assertSame('Sara', $sara->name);
        $this->assertTrue(Hash::check(self::PASSWORD, $sara->password));
        $this->assertTrue($sara->hasVerifiedEmail());
        $this->assertFalse($sara->isSuperAdmin());
        $this->assertSame([$this->team->id], $sara->teams()->pluck('teams.id')->all());   // aucune autre team
        $this->assertSame(TeamRole::Manager, $sara->roleIn($this->team));
        $this->assertSame(1, Team::count());
        $this->assertSame(0, TeamInvitation::count());                                    // à usage unique

        Notification::assertNothingSent();                       // pas d'email de vérification
        Mail::assertNotQueued(NewAccountMail::class);            // il a déjà une team : rien à signaler

        $this->get(route('dashboard'))->assertOk();
    }

    public function test_the_creation_is_recorded_in_the_audit_log(): void
    {
        $this->signUp($this->invitation()->acceptUrl());

        $sara = User::where('email', 'sara@company.com')->sole();
        $this->assertSame(['user.create', 'member.join'], AuditLog::orderBy('id')->pluck('action')->all());
        $create = AuditLog::where('action', 'user.create')->sole();
        $this->assertSame($sara->id, $create->user_id);
        $this->assertSame(['email' => 'sara@company.com', 'via' => 'invitation', 'team' => 'Payments'], $create->details);
        $this->assertSame($this->team->id, AuditLog::where('action', 'member.join')->sole()->team_id);
    }

    public function test_it_works_whatever_the_registration_mode(): void
    {
        config(['registration.mode' => 'domain', 'registration.domains' => 'other.org']);

        $this->signUp($this->invitation()->acceptUrl())->assertRedirect(route('dashboard'));

        $this->assertDatabaseHas('users', ['email' => 'sara@company.com']);
    }

    public function test_the_form_is_validated(): void
    {
        $url = $this->invitation()->acceptUrl();

        $this->from($url)->signUp($url, ['name' => ''])->assertSessionHasErrors('name');
        $this->from($url)->signUp($url, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->from($url)->signUp($url, ['password_confirmation' => 'something-else'])->assertSessionHasErrors('password');

        $this->assertSame(0, User::where('email', 'sara@company.com')->count());
        $this->assertSame(1, TeamInvitation::count());
    }

    public function test_an_email_field_sent_anyway_is_ignored(): void
    {
        $this->signUp($this->invitation()->acceptUrl(), ['email' => 'attacker@evil.io']);

        $this->assertDatabaseHas('users', ['email' => 'sara@company.com']);
        $this->assertDatabaseMissing('users', ['email' => 'attacker@evil.io']);
    }

    public function test_a_link_cannot_be_used_twice(): void
    {
        $url = $this->invitation()->acceptUrl();
        $this->signUp($url);
        $this->post('/logout');

        $this->get($url)->assertOk()->assertSee('no longer valid');
        $this->signUp($url, ['name' => 'Someone else'])->assertOk()->assertSee('no longer valid');

        $this->assertSame(1, User::where('email', 'sara@company.com')->count());
    }

    public function test_an_expired_tampered_or_cancelled_link_creates_nothing(): void
    {
        $invitation = $this->invitation();
        $url = $invitation->acceptUrl();

        $this->signUp(str_replace('signature=', 'signature=0', $url))->assertOk()->assertSee('no longer valid');
        $this->signUp(str_replace('/invitations/'.$invitation->id.'/', '/invitations/'.($invitation->id + 1).'/', $url))
            ->assertOk()->assertSee('no longer valid');

        $this->travel(8)->days();
        $this->get($url)->assertOk()->assertSee('no longer valid');
        $this->signUp($url)->assertOk()->assertSee('no longer valid');
        $this->travelBack();

        $invitation->delete();                                         // annulée par l'Admin
        $this->signUp($url)->assertOk()->assertSee('no longer valid');

        $this->assertSame(0, User::where('email', 'sara@company.com')->count());
    }

    public function test_an_account_created_meanwhile_must_log_in(): void
    {
        $url = $this->invitation()->acceptUrl();
        User::factory()->create(['email' => 'sara@company.com']);

        $this->signUp($url)
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'An account already exists for this address. Log in to accept the invitation.');
        $this->assertSame($url, session('url.intended'));
        $this->assertGuest();
    }

    // ─── Compte existant ────────────────────────────────────────────────

    public function test_an_existing_account_logs_in_then_joins(): void
    {
        $sara = User::factory()->create(['email' => 'sara@company.com', 'password' => self::PASSWORD]);
        $url = $this->invitation()->acceptUrl();

        $this->get($url)->assertRedirect(route('login'));
        $this->post('/login', ['email' => 'sara@company.com', 'password' => self::PASSWORD])->assertRedirect($url);
        $this->get($url)->assertRedirect(route('dashboard'));

        $this->assertSame(TeamRole::Manager, $sara->roleIn($this->team));
    }

    public function test_an_unverified_account_is_verified_by_the_link(): void
    {
        $sara = User::factory()->unverified()->create(['email' => 'sara@company.com']);

        $this->actingAs($sara)->get($this->invitation()->acceptUrl())->assertRedirect(route('dashboard'));

        $this->assertTrue($sara->fresh()->hasVerifiedEmail());
        $this->assertSame(TeamRole::Manager, $sara->fresh()->roleIn($this->team));
    }

    public function test_a_logged_in_user_cannot_post_the_create_account_form(): void
    {
        $url = $this->invitation()->acceptUrl();

        $this->actingAs($this->admin)->signUp($url)->assertRedirect();

        $this->assertSame(0, User::where('email', 'sara@company.com')->count());
    }

    // ─── Invitation par un Super Admin ──────────────────────────────────

    public function test_a_super_admin_invites_into_any_team_with_any_role(): void
    {
        $it = User::factory()->superAdmin()->create(['name' => 'IT']);

        $this->actingAs($it)->from('/')->post(route('teams.invitations.store', $this->team), ['email' => 'Boss@Company.com', 'role' => 'admin'])
            ->assertSessionHas('success', 'Invitation sent to boss@company.com.');

        $invitation = TeamInvitation::sole();
        $this->assertSame(TeamRole::Admin, $invitation->role);
        $this->assertSame($it->id, $invitation->invited_by);
        Mail::assertQueued(TeamInvitationMail::class, fn ($mail) => $mail->hasTo('boss@company.com'));

        $this->actingAs($it)->from('/')->delete(route('teams.invitations.destroy', [$this->team, $invitation]))
            ->assertSessionHas('success');
        $this->assertSame(0, TeamInvitation::count());
        // … sans rien gagner d'autre dans la team
        $this->actingAs($it)->get(route('teams.members.index', $this->team))->assertRedirect(route('access.waiting'));
    }

    public function test_a_member_who_is_not_admin_still_cannot_invite(): void
    {
        $viewer = User::factory()->create();
        $this->team->members()->attach($viewer, ['role' => TeamRole::Manager]);

        $this->actingAs($viewer)->post(route('teams.invitations.store', $this->team), ['email' => 'x@company.com', 'role' => 'admin'])
            ->assertForbidden();
        $this->assertSame(0, TeamInvitation::count());
    }

    public function test_platform_invite_command(): void
    {
        $this->artisan('platform:invite', ['email' => 'Boss@Company.com', 'team' => 'Payments', '--role' => 'admin'])
            ->expectsOutputToContain('Invitation sent to boss@company.com: Payments as Admin')
            ->assertSuccessful();
        $this->artisan('platform:invite', ['email' => 'x@company.com', 'team' => (string) $this->team->id])->assertSuccessful();

        $this->assertSame(TeamRole::Admin, TeamInvitation::where('email', 'boss@company.com')->sole()->role);
        $this->assertSame(TeamRole::Viewer, TeamInvitation::where('email', 'x@company.com')->sole()->role);
        $this->assertNull(TeamInvitation::where('email', 'boss@company.com')->sole()->invited_by);
        $this->assertSame('console', AuditLog::where('action', 'member.invite')->first()->details['via']);
        Mail::assertQueuedCount(2);
        $this->assertStringContainsString('An administrator invited you', (new TeamInvitationMail(TeamInvitation::first()))->render());

        $this->artisan('platform:invite', ['email' => 'x@company.com', 'team' => 'Nope'])->assertFailed();
        $this->artisan('platform:invite', ['email' => 'not-an-email', 'team' => 'Payments'])->assertFailed();
        $this->artisan('platform:invite', ['email' => 'y@company.com', 'team' => 'Payments', '--role' => 'owner'])->assertFailed();
        $this->artisan('platform:invite', ['email' => $this->admin->email, 'team' => 'Payments'])
            ->expectsOutputToContain('already a member')->assertFailed();
    }
}
