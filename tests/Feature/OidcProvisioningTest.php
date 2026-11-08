<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Http\Controllers\OidcController;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\SsoAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\FakesOidcProvider;
use Tests\TestCase;

/**
 * Comptes à la première connexion SSO (SCRUM-89) : invitation, REGISTRATION_MODE, liaison, comptes sans mot de passe
 * (confirmation d'identité par le SSO), « My account » et console d'administration.
 */
class OidcProvisioningTest extends TestCase
{
    use FakesOidcProvider, RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Mail::fake();
        $this->setUpOidcProvider();
        config(['registration.mode' => 'invite', 'registration.domains' => '', 'registration.contact' => 'it-support@company.com']);
        $this->team = Team::factory()->create(['name' => 'Payments']);
        $this->team->members()->attach(User::factory()->create(['name' => 'Wissal']), ['role' => TeamRole::Admin]);
    }

    private function invitation(string $email = 'alice@company.com', TeamRole $role = TeamRole::Viewer, ?Team $team = null): TeamInvitation
    {
        return TeamInvitation::create([
            'team_id' => ($team ?? $this->team)->id, 'email' => $email, 'role' => $role,
            'expires_at' => now()->addDays(TeamInvitation::VALID_DAYS),
        ]);
    }

    private function alice(): User
    {
        return User::where('email', 'alice@company.com')->sole();
    }

    /** Compte créé par SSO, connecté, sans mot de passe */
    private function ssoOnlyUser(): User
    {
        $user = User::factory()->withTeam(TeamRole::Viewer)->create([
            'email' => 'alice@company.com', 'password' => null, 'oidc_issuer' => self::ISSUER, 'oidc_subject' => 'subject-alice',
        ]);
        $this->actingAs($user);

        return $user;
    }

    // ─── Invitation en attente ────────────────────────────────────────────

    public function test_an_invitation_creates_the_account_without_password_and_joins_the_team(): void
    {
        $invitation = $this->invitation();

        $this->signIn()->assertRedirect(route('dashboard'));

        $alice = $this->alice();
        $this->assertAuthenticatedAs($alice);
        $this->assertNull($alice->password);
        $this->assertFalse($alice->hasPassword());
        $this->assertTrue($alice->hasVerifiedEmail());
        $this->assertSame('Alice', $alice->name);
        $this->assertSame(['subject-alice', self::ISSUER], [$alice->oidc_subject, $alice->oidc_issuer]);
        $this->assertSame(TeamRole::Viewer, $alice->roleIn($this->team));
        $this->assertModelMissing($invitation);
        $this->assertSame(
            ['email' => 'alice@company.com', 'via' => 'sso', 'provider' => 'sso.company.com', 'team' => 'Payments'],
            AuditLog::where('action', 'user.create')->sole()->details,
        );
        $this->assertSame(1, AuditLog::where('action', 'member.join')->count());
        $this->assertSame(1, AuditLog::where('action', 'user.login_sso')->count());
    }

    public function test_every_pending_invitation_is_accepted(): void
    {
        $other = Team::factory()->create(['name' => 'Data']);
        $this->invitation();
        $this->invitation(team: $other, role: TeamRole::Manager);

        $this->signIn()->assertRedirect(route('dashboard'));

        $this->assertSame(TeamRole::Manager, $this->alice()->roleIn($other));
        $this->assertSame(0, TeamInvitation::count());
    }

    public function test_an_invited_admin_sets_up_two_factor_without_a_password(): void
    {
        config(['security.two_factor_for_admins' => true]);
        $this->invitation(role: TeamRole::Admin);

        $this->signIn();
        $this->get(route('dashboard'))->assertRedirect(route('account.two-factor'));

        // La connexion SSO vaut confirmation : pas de mot de passe demandé
        $this->get(route('account.two-factor'))->assertOk()->assertDontSee('Your password')->assertDontSee('Confirm with single sign-on');
        $this->post(route('account.two-factor.enable'))->assertRedirect(route('account.two-factor'));
        $this->assertNotNull($this->alice()->fresh()->two_factor_secret);
    }

    public function test_an_expired_invitation_does_not_open_the_platform(): void
    {
        $this->invitation()->forceFill(['expires_at' => now()->subDay()])->save();

        $this->signIn()->assertRedirect(route('oidc.no-access'));

        $this->assertSame(1, User::count());
    }

    public function test_an_unverified_email_never_creates_an_account(): void
    {
        $this->invitation();
        $this->claims = ['email_verified' => false];

        $this->signIn()->assertRedirect(route('login'))->assertSessionHasErrors(['sso' => SsoAccountService::EMAIL_UNVERIFIED]);

        $this->assertGuest();
        $this->assertSame(1, User::count());
    }

    // ─── Personne inconnue, selon REGISTRATION_MODE ──────────────────────

    public function test_invite_mode_shows_waiting_for_access_without_creating_an_account(): void
    {
        $this->signIn()->assertRedirect(route('oidc.no-access'));

        $this->get(route('oidc.no-access'))->assertOk()
            ->assertSee('Waiting for access')->assertSee('alice@company.com')->assertSee('it-support@company.com')->assertSee('No account was created');
        $this->assertGuest();
        $this->assertSame(1, User::count());
        // Message affiché une fois : en rechargeant la page, retour à la connexion
        $this->get(route('oidc.no-access'))->assertRedirect(route('login'));
    }

    public function test_domain_mode_creates_an_account_without_team_for_an_allowed_domain(): void
    {
        config(['registration.mode' => 'domain', 'registration.domains' => 'company.com']);

        $this->signIn()->assertRedirect(route('dashboard'));
        $this->get(route('dashboard'))->assertRedirect(route('access.waiting'));

        $this->assertSame(0, $this->alice()->teams()->count());
        $this->assertSame('domain', AuditLog::where('action', 'user.create')->sole()->details['mode']);
    }

    public function test_domain_mode_refuses_another_domain(): void
    {
        config(['registration.mode' => 'domain', 'registration.domains' => 'company.fr']);

        $this->signIn()->assertRedirect(route('oidc.no-access'));

        $this->assertSame(1, User::count());
    }

    public function test_open_mode_creates_an_account_without_team(): void
    {
        config(['registration.mode' => 'open']);
        $this->claims = ['email' => 'Bob.Martin@Partner.org', 'sub' => 'subject-bob'];

        $this->signIn()->assertRedirect(route('dashboard'));

        $this->assertTrue(User::where('email', 'bob.martin@partner.org')->exists());
    }

    public function test_the_name_falls_back_on_other_claims(): void
    {
        config(['registration.mode' => 'open']);
        $this->withoutClaims = ['name'];
        $this->claims = ['given_name' => 'Alice', 'family_name' => 'Durand'];
        $this->signIn();
        $this->assertSame('Alice Durand', $this->alice()->name);

        auth()->logout();
        $this->withoutClaims = ['name', 'given_name', 'family_name', 'preferred_username'];
        $this->claims = ['email' => 'nina.newcomer@company.com', 'sub' => 'subject-nina'];
        $this->signIn();
        $this->assertSame('nina.newcomer', User::where('email', 'nina.newcomer@company.com')->sole()->name);
    }

    // ─── Fournisseur de confiance, email de l'annuaire ───────────────────

    public function test_a_trusted_provider_without_email_verified_can_link_and_create(): void
    {
        $this->invitation();
        $this->withoutClaims = ['email_verified'];   // Entra ID

        $this->signIn()->assertSessionHasErrors(['sso' => SsoAccountService::EMAIL_UNVERIFIED]);

        config(['oidc.trust_email' => true]);
        $this->signIn()->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->alice());
    }

    public function test_a_new_directory_email_is_reported_but_never_applied(): void
    {
        User::factory()->withTeam()->create(['email' => 'alice@company.com', 'oidc_issuer' => self::ISSUER, 'oidc_subject' => 'subject-alice']);
        $this->claims = ['email' => 'alice.durand@company.com'];

        $this->signIn()->assertRedirect(route('dashboard'));
        auth()->logout();
        $this->signIn();

        $this->assertSame('alice@company.com', $this->alice()->email);
        $this->assertSame(
            ['email' => 'alice@company.com', 'directory_email' => 'alice.durand@company.com'],
            AuditLog::where('action', 'user.sso_email_mismatch')->sole()->details,
        );
    }

    // ─── Compte sans mot de passe ─────────────────────────────────────────

    public function test_an_sso_only_account_cannot_log_in_with_a_password(): void
    {
        User::factory()->create(['email' => 'alice@company.com', 'password' => null]);

        $this->post(route('login'), ['email' => 'alice@company.com', 'password' => ''])->assertSessionHasErrors();
        $this->post(route('login'), ['email' => 'alice@company.com', 'password' => 'anything'])->assertSessionHasErrors();

        $this->assertGuest();
    }

    public function test_reauth_forces_a_new_login_and_confirms_the_identity(): void
    {
        $this->ssoOnlyUser();

        $this->get(route('oidc.reauth'))->assertRedirectContains('prompt=login')->assertRedirectContains('max_age=0');
        $this->claims = ['auth_time' => time()];

        $this->reauth()->assertRedirect(route('account.edit'))->assertSessionHas('success', 'Identity confirmed.');
        $this->assertNotNull(session('auth.password_confirmed_at'));
    }

    public function test_reauth_goes_back_to_the_two_factor_page(): void
    {
        $this->ssoOnlyUser();
        $this->claims = ['auth_time' => time()];

        $this->reauth(['to' => 'two-factor'])->assertRedirect(route('account.two-factor'));
    }

    public function test_reauth_with_another_identity_or_an_old_session_is_refused(): void
    {
        $this->ssoOnlyUser();

        $this->claims = ['auth_time' => time(), 'sub' => 'subject-mallory'];
        $this->reauth()->assertRedirect(route('account.edit'))->assertSessionHas('error', OidcController::REAUTH_FAILED);

        // Pas de nouvelle authentification chez le fournisseur (session SSO déjà ouverte depuis longtemps)
        $this->claims = ['auth_time' => time() - 3600];
        $this->reauth()->assertSessionHas('error', OidcController::REAUTH_FAILED);

        $this->claims = [];
        $this->reauth()->assertSessionHas('error', OidcController::REAUTH_FAILED);

        $this->assertNull(session('auth.password_confirmed_at'));
        $this->assertAuthenticated();
    }

    public function test_reauth_is_only_for_linked_accounts(): void
    {
        $this->actingAs(User::factory()->withTeam()->create());

        $this->get(route('oidc.reauth'))->assertNotFound();
    }

    public function test_the_confirm_password_page_offers_sso_to_an_account_without_password(): void
    {
        $this->ssoOnlyUser();

        $this->get(route('password.confirm'))->assertOk()
            ->assertSee('Confirm your identity')->assertSee(route('oidc.reauth'))->assertDontSee('name="password"', false);
    }

    public function test_an_sso_only_account_is_deleted_after_confirming_with_sso(): void
    {
        $user = $this->ssoOnlyUser();

        $this->get(route('account.edit'))->assertOk()->assertSee('Confirm with single sign-on to delete');
        $this->delete(route('account.destroy'))->assertSessionHas('error');
        $this->assertModelExists($user);

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('account.destroy'))->assertRedirect(route('login'));
        $this->assertModelMissing($user);
    }

    // ─── My account ───────────────────────────────────────────────────────

    public function test_my_account_shows_how_the_person_signs_in(): void
    {
        $this->ssoOnlyUser();

        $this->get(route('account.edit'))->assertOk()
            ->assertSee('Single sign-on')->assertSee('sso.company.com')->assertSee('No password on this platform')
            ->assertDontSee('Unlink');
    }

    public function test_sso_is_unlinked_only_when_a_password_remains(): void
    {
        $user = $this->ssoOnlyUser();
        $this->delete(route('account.sso.destroy'))->assertSessionHas('error');
        $this->assertTrue($user->fresh()->hasSso());

        $user->forceFill(['password' => Hash::make('Secret-Password-1')])->save();
        $this->get(route('account.edit'))->assertSee('Unlink');
        $this->delete(route('account.sso.destroy'), ['password' => 'wrong'])->assertSessionHasErrors('password', null, 'unlinkSso');
        $this->assertTrue($user->fresh()->hasSso());

        $this->delete(route('account.sso.destroy'), ['password' => 'Secret-Password-1'])->assertSessionHas('success');
        $this->assertFalse($user->fresh()->hasSso());
        $this->assertSame(['provider' => 'sso.company.com'], AuditLog::where('action', 'user.sso_unlink')->sole()->details);
    }

    // ─── Console d'administration ─────────────────────────────────────────

    public function test_the_admin_console_shows_and_filters_sso_accounts(): void
    {
        User::factory()->create(['name' => 'Sso Person', 'password' => null, 'oidc_issuer' => self::ISSUER, 'oidc_subject' => 'subject-sso']);
        User::factory()->create(['name' => 'Password Person']);
        $admin = User::factory()->superAdmin()->create(['two_factor_secret' => encrypt('x'), 'two_factor_confirmed_at' => now()]);
        $this->actingAs($admin);

        $this->get(route('admin.users.index'))->assertOk()->assertSee('Linked')->assertSee('No password');
        $this->get(route('admin.users.index', ['sign_in' => 'sso']))->assertSee('Sso Person')->assertDontSee('Password Person');
        $this->get(route('admin.users.index', ['sign_in' => 'password']))->assertSee('Password Person')->assertDontSee('Sso Person');
        $this->get(route('admin.users.index', ['sign_in' => 'bogus']))->assertSessionHasErrors('sign_in');
    }
}
