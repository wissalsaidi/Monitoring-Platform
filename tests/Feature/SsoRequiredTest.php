<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Http\Middleware\DisablePasswordAuthWhenSsoRequired;
use App\Http\Middleware\EnforceSsoSession;
use App\Mail\BreakGlassLoginMail;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\PlatformAdminService;
use App\Services\SsoRequirementMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\FakesOidcProvider;
use Tests\TestCase;

/**
 * SSO obligatoire, MFA de l'annuaire, compte de secours, durée de session et déconnexion (SCRUM-90).
 */
class SsoRequiredTest extends TestCase
{
    use FakesOidcProvider, RefreshDatabase;

    private const PASSWORD = 'Emergency-Password-1';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Mail::fake();
        $this->setUpOidcProvider();
        $this->discovery['end_session_endpoint'] = self::ISSUER.'/protocol/openid-connect/logout';
        config(['oidc.required' => true, 'security.two_factor_for_admins' => true]);
    }

    private function alice(array $attributes = []): User
    {
        return User::factory()->withTeam(TeamRole::Viewer)->create([
            'email' => 'alice@company.com', 'oidc_issuer' => self::ISSUER, 'oidc_subject' => 'subject-alice', ...$attributes,
        ]);
    }

    private function withTwoFactor(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-one'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    private function emergencyAdmin(array $attributes = []): User
    {
        return User::factory()->superAdmin()->create([
            'email' => 'it-emergency@company.com', 'password' => self::PASSWORD, 'break_glass_at' => now(), ...$attributes,
        ]);
    }

    // ─── Page de connexion et routes de mot de passe ─────────────────────

    public function test_only_the_sso_button_remains_on_the_login_page(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('Sign in with Company SSO')->assertDontSee('name="password"', false)
            ->assertDontSee('Forgot password?')->assertDontSee('or with your password');

        config(['oidc.required' => false]);
        $this->get(route('login'))->assertSee('name="password"', false)->assertSee('or with your password');
    }

    public function test_a_forged_password_login_is_refused(): void
    {
        $user = User::factory()->withTeam()->create(['password' => 'Secret-Password-1']);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Secret-Password-1'])
            ->assertRedirect(route('login'))->assertSessionHasErrors(['sso' => DisablePasswordAuthWhenSsoRequired::PASSWORD_DISABLED]);

        $this->assertGuest();
    }

    public function test_registration_and_password_reset_are_disabled(): void
    {
        config(['registration.mode' => 'open']);

        $this->get(route('register'))->assertNotFound();
        $this->post(route('register.store'), ['name' => 'Bob', 'email' => 'bob@company.com'])->assertNotFound();
        $this->get(route('password.request'))->assertNotFound();
        $this->post(route('password.email'), ['email' => 'alice@company.com'])->assertNotFound();
        $this->get(route('password.reset', ['token' => 'abc']))->assertNotFound();
        $this->post(route('password.update'), ['token' => 'abc'])->assertNotFound();
        $this->assertSame(0, User::count());
    }

    public function test_sso_required_without_provider_does_not_lock_the_platform(): void
    {
        config(['oidc.issuer' => '']);

        $this->get(route('login'))->assertSee('name="password"', false);
        $this->get(route('break-glass'))->assertNotFound();
    }

    // ─── Sessions ─────────────────────────────────────────────────────────

    public function test_a_password_session_is_closed_when_sso_becomes_required(): void
    {
        $this->actingAs(User::factory()->withTeam()->create());

        $this->get(route('dashboard'))->assertRedirect(route('login'))
            ->assertSessionHasErrors(['sso' => EnforceSsoSession::SSO_REQUIRED]);
        $this->assertGuest();
    }

    public function test_an_sso_session_lasts_the_configured_lifetime(): void
    {
        $alice = $this->alice();

        $this->signIn()->assertRedirect(route('dashboard'));
        $this->assertSame('sso', session('auth.via'));

        $this->travel(479)->minutes();
        $this->get(route('dashboard'))->assertOk();

        $this->travel(2)->minutes();
        $this->get(route('dashboard'))->assertRedirect(route('login'))
            ->assertSessionHasErrors(['sso' => EnforceSsoSession::EXPIRED]);
        $this->assertGuest();

        // Même sans SSO obligatoire, une session SSO a la même durée
        config(['oidc.required' => false, 'oidc.session_lifetime' => 60]);
        $this->signIn();
        $this->travel(61)->minutes();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertNotNull($alice);
    }

    // ─── MFA de l'annuaire ─────────────────────────────────────────────────

    public function test_directory_mfa_skips_the_platform_two_factor(): void
    {
        $alice = $this->withTwoFactor($this->alice());
        $this->claims = ['amr' => ['pwd', 'otp']];

        $this->signIn()->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($alice);
        $this->assertTrue(session('auth.mfa'));
        $this->assertSame(['mfa' => 'directory'], AuditLog::where('action', 'user.login_sso')->sole()->details);
    }

    public function test_without_directory_mfa_the_platform_two_factor_is_asked(): void
    {
        $alice = $this->withTwoFactor($this->alice());
        $this->claims = ['amr' => ['pwd']];

        $this->signIn()->assertRedirect(route('two-factor.login'));
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one'])->assertRedirect();

        // Le défi passé, la session reste une session SSO (durée, déconnexion chez le fournisseur)
        $this->assertAuthenticatedAs($alice);
        $this->assertSame('sso', session('auth.via'));
        $this->assertFalse(session('auth.mfa'));
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_an_admin_with_directory_mfa_does_not_have_to_set_up_platform_two_factor(): void
    {
        $alice = $this->alice();
        $alice->teams()->updateExistingPivot($alice->teams()->first()->id, ['role' => TeamRole::Admin]);

        $this->claims = ['acr' => 'urn:company:mfa'];
        config(['oidc.mfa_acr' => ['urn:company:mfa']]);
        $this->signIn();
        $this->get(route('dashboard'))->assertOk();

        auth()->logout();
        $this->claims = ['acr' => '1'];
        $this->signIn();
        $this->get(route('dashboard'))->assertRedirect(route('account.two-factor'));
    }

    // ─── Compte de secours ─────────────────────────────────────────────────

    public function test_the_emergency_account_logs_in_and_the_other_super_admins_are_notified(): void
    {
        $emergency = $this->emergencyAdmin();
        $other = User::factory()->superAdmin()->create();
        User::factory()->superAdmin()->create(['deactivated_at' => now()]);

        $this->get(route('break-glass'))->assertOk()->assertSee('Emergency access');
        $this->post(route('break-glass.store'), ['email' => 'IT-Emergency@company.com', 'password' => self::PASSWORD])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($emergency);
        $this->assertSame('break_glass', session('auth.via'));
        // Pas encore de 2FA : à mettre en place avant toute autre page
        $this->get(route('dashboard'))->assertRedirect(route('account.two-factor'));
        $this->get(route('account.two-factor'))->assertOk();
        $this->assertSame(['email' => 'it-emergency@company.com'], AuditLog::where('action', 'user.login_break_glass')->sole()->details);
        Mail::assertQueued(BreakGlassLoginMail::class, 1);
        Mail::assertQueued(BreakGlassLoginMail::class, fn ($mail) => $mail->hasTo($other->email) && $mail->account->is($emergency));
    }

    public function test_the_emergency_account_goes_through_platform_two_factor(): void
    {
        $emergency = $this->withTwoFactor($this->emergencyAdmin());

        $this->post(route('break-glass.store'), ['email' => $emergency->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one']);
        $this->assertAuthenticatedAs($emergency);
        $this->assertSame('break_glass', session('auth.via'));
    }

    public function test_only_the_designated_account_can_use_emergency_access(): void
    {
        $this->emergencyAdmin();
        User::factory()->superAdmin()->create(['email' => 'other-admin@company.com', 'password' => self::PASSWORD]);
        $this->emergencyAdmin(['email' => 'demoted@company.com', 'platform_role' => null]);

        foreach ([
            ['it-emergency@company.com', 'wrong-password'],
            ['other-admin@company.com', self::PASSWORD],
            ['demoted@company.com', self::PASSWORD],
            ['nobody@company.com', self::PASSWORD],
        ] as [$email, $password]) {
            $this->post(route('break-glass.store'), ['email' => $email, 'password' => $password])->assertSessionHasErrors('email');
            $this->assertGuest();
        }
        $this->assertSame(0, AuditLog::where('action', 'user.login_break_glass')->count());
        Mail::assertNothingQueued();
    }

    public function test_emergency_access_does_not_exist_when_sso_is_optional(): void
    {
        config(['oidc.required' => false]);
        $this->emergencyAdmin();

        $this->get(route('break-glass'))->assertNotFound();
        $this->post(route('break-glass.store'), ['email' => 'it-emergency@company.com', 'password' => self::PASSWORD])->assertNotFound();
    }

    public function test_an_emergency_session_ends_when_the_designation_is_removed(): void
    {
        $emergency = $this->withTwoFactor($this->emergencyAdmin());
        $this->post(route('break-glass.store'), ['email' => $emergency->email, 'password' => self::PASSWORD]);
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one']);
        $this->get(route('admin.users.index'))->assertOk();

        $this->artisan('platform:break-glass', ['email' => $emergency->email, '--revoke' => true])->assertSuccessful();
        $this->app['auth']->forgetGuards();     // comme une nouvelle requête : compte relu en base

        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // ─── Déconnexion ──────────────────────────────────────────────────────

    public function test_logout_also_ends_the_session_at_the_provider(): void
    {
        $this->alice();
        $this->signIn();
        $idToken = session('auth.id_token');
        $this->assertIsString($idToken);

        $response = $this->post(route('logout'));

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(self::ISSUER.'/protocol/openid-connect/logout?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame([
            'id_token_hint' => $idToken, 'client_id' => self::CLIENT_ID, 'post_logout_redirect_uri' => route('login'),
        ], $query);
        $this->assertGuest();
    }

    public function test_logout_stays_local_without_sso_session_or_end_session_endpoint(): void
    {
        $emergency = $this->withTwoFactor($this->emergencyAdmin());
        $this->post(route('break-glass.store'), ['email' => $emergency->email, 'password' => self::PASSWORD]);
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one']);
        $this->post(route('logout'))->assertRedirect('/');

        unset($this->discovery['end_session_endpoint']);
        $this->alice();
        $this->signIn();
        $this->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    // ─── Le reste de la plateforme ────────────────────────────────────────

    public function test_an_invitation_is_accepted_through_sso(): void
    {
        $team = Team::factory()->create(['name' => 'Payments']);
        $invitation = TeamInvitation::create([
            'team_id' => $team->id, 'email' => 'alice@company.com', 'role' => TeamRole::Viewer, 'expires_at' => now()->addDays(7),
        ]);

        $this->get($invitation->acceptUrl())->assertOk()->assertSee('Join your team')->assertSee(route('oidc.redirect'))
            ->assertDontSee('name="password"', false);
        $this->post($invitation->acceptUrl(), ['name' => 'Alice', 'password' => 'x', 'password_confirmation' => 'x'])->assertNotFound();

        $this->signIn()->assertRedirect(route('dashboard'));
        $this->assertSame(TeamRole::Viewer, User::where('email', 'alice@company.com')->sole()->roleIn($team));
    }

    public function test_sso_cannot_be_unlinked_and_passwords_cannot_be_reset(): void
    {
        $alice = $this->alice(['password' => 'Secret-Password-1']);
        $this->signIn();
        $this->get(route('account.edit'))->assertDontSee('Unlink');
        $this->delete(route('account.sso.destroy'), ['password' => 'Secret-Password-1'])->assertSessionHas('error');
        $this->assertTrue($alice->fresh()->hasSso());
        auth()->logout();

        $admin = $this->withTwoFactor($this->emergencyAdmin());
        $this->post(route('break-glass.store'), ['email' => $admin->email, 'password' => self::PASSWORD]);
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one']);
        $this->post(route('admin.users.password-reset', $alice))->assertSessionHas('error');
        $this->assertSame(0, AuditLog::where('action', 'user.password_reset_forced')->count());
    }

    // ─── Commandes et audit ───────────────────────────────────────────────

    public function test_the_break_glass_command_designates_an_active_super_admin(): void
    {
        $viewer = User::factory()->create(['email' => 'viewer@company.com']);
        $this->artisan('platform:break-glass', ['email' => 'viewer@company.com'])->assertFailed();
        $this->artisan('platform:break-glass', ['email' => 'nobody@company.com'])->assertFailed();
        $this->assertNull($viewer->fresh()->break_glass_at);

        $admin = User::factory()->superAdmin()->create(['email' => 'it@company.com']);
        $this->artisan('platform:break-glass', ['email' => 'IT@company.com'])
            ->expectsOutputToContain('emergency access account')->assertSuccessful();
        $this->assertTrue($admin->fresh()->isBreakGlass());
        $this->assertSame(1, AuditLog::where('action', 'platform.break_glass_grant')->count());
        $this->artisan('platform:list-admins')->expectsOutputToContain('yes')->assertSuccessful();

        $this->artisan('platform:break-glass', ['email' => 'it@company.com', '--revoke' => true])->assertSuccessful();
        $this->assertNull($admin->fresh()->break_glass_at);
        $this->assertSame(1, AuditLog::where('action', 'platform.break_glass_revoke')->count());
    }

    public function test_an_sso_only_super_admin_chooses_a_password_for_emergency_access(): void
    {
        $admin = User::factory()->superAdmin()->create(['email' => 'it@company.com', 'password' => null]);

        $this->artisan('platform:break-glass', ['email' => 'it@company.com', '--no-interaction' => true])->assertFailed();
        $this->assertNull($admin->fresh()->break_glass_at);

        $this->artisan('platform:break-glass', ['email' => 'it@company.com'])
            ->expectsQuestion('Password (hidden)', self::PASSWORD)
            ->expectsQuestion('Confirm the password', self::PASSWORD)
            ->assertSuccessful();
        $this->assertTrue($admin->fresh()->hasPassword());
        $this->assertTrue($admin->fresh()->isBreakGlass());
    }

    public function test_revoking_a_super_admin_removes_emergency_access(): void
    {
        $emergency = $this->emergencyAdmin();
        User::factory()->superAdmin()->create();

        app(PlatformAdminService::class)->revoke($emergency);

        $this->assertNull($emergency->fresh()->break_glass_at);
    }

    public function test_changes_of_the_requirement_are_audited_once(): void
    {
        $monitor = app(SsoRequirementMonitor::class);

        $monitor->check();
        $monitor->check();
        config(['oidc.required' => false]);
        $monitor->check();
        $monitor->check();

        $this->assertSame(
            [['required' => true, 'via' => 'system'], ['required' => false, 'via' => 'system']],
            AuditLog::where('action', 'platform.sso_required')->orderBy('id')->pluck('details')->all(),
        );
    }
}
