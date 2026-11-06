<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * Double authentification TOTP (SCRUM-86) : activation, connexion avec un code ou un code de secours, obligation pour
 * les administrateurs, réinitialisation par un Super Admin, audit.
 */
class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Str0ng-password';

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['security.two_factor_for_admins' => true]);
        $this->team = Team::factory()->create(['name' => 'Payments']);
    }

    private function member(TeamRole $role, array $attributes = []): User
    {
        $user = User::factory()->create(['password' => self::PASSWORD, ...$attributes]);
        $this->team->members()->attach($user, ['role' => $role]);

        return $user;
    }

    /** 2FA déjà confirmée, avec deux codes de secours connus */
    private function withTwoFactor(User $user): User
    {
        $user->forceFill([
            'two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey()),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-one', 'recovery-two'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $user;
    }

    private function currentCode(User $user): string
    {
        return app(Google2FA::class)->getCurrentOtp(decrypt($user->fresh()->two_factor_secret));
    }

    // ─── Obligation ─────────────────────────────────────────────────────

    public function test_admins_must_set_up_two_factor_before_anything_else(): void
    {
        $admin = $this->member(TeamRole::Admin);
        $superAdmin = User::factory()->superAdmin()->create();

        foreach ([$admin, $superAdmin] as $user) {
            $this->actingAs($user)->get('/')->assertRedirect(route('account.two-factor'));
            $this->actingAs($user)->get(route('account.two-factor'))->assertOk()
                ->assertSee(['you must use two-factor authentication', 'Set up two-factor authentication']);
            $this->actingAs($user)->get(route('account.edit'))->assertOk()->assertSee('required for your role');
        }
        $this->actingAs($superAdmin)->get(route('admin.users.index'))->assertRedirect(route('account.two-factor'));
    }

    public function test_other_roles_are_not_forced(): void
    {
        $viewer = $this->member(TeamRole::Viewer);
        $manager = $this->member(TeamRole::Manager);

        $this->actingAs($viewer)->get('/')->assertOk();
        $this->actingAs($manager)->get('/')->assertOk();
        $this->actingAs($viewer)->get(route('account.two-factor'))->assertOk()->assertDontSee('you must use two-factor');
    }

    public function test_an_admin_with_two_factor_goes_through(): void
    {
        $admin = $this->withTwoFactor($this->member(TeamRole::Admin));

        $this->actingAs($admin)->get('/')->assertOk();
    }

    public function test_the_obligation_can_be_turned_off_for_a_throwaway_demo(): void
    {
        config(['security.two_factor_for_admins' => false]);

        $this->actingAs($this->member(TeamRole::Admin))->get('/')->assertOk();
    }

    public function test_grafana_is_closed_to_an_admin_without_two_factor(): void
    {
        $admin = $this->member(TeamRole::Admin, ['grafana_user_id' => 42]);

        $this->actingAs($admin)->get(route('grafana.auth'))->assertForbidden();
        $this->actingAs($this->withTwoFactor($admin))->get(route('grafana.auth'))->assertOk();
    }

    // ─── Activation ─────────────────────────────────────────────────────

    public function test_enabling_needs_the_password_then_a_valid_first_code(): void
    {
        $admin = $this->member(TeamRole::Admin);

        // Mot de passe redemandé
        $this->actingAs($admin)->post(route('two-factor.enable'))->assertRedirect(route('password.confirm'));
        $this->get(route('password.confirm'))->assertOk()->assertSee('Confirm your password');
        $this->post(route('password.confirm.store'), ['password' => self::PASSWORD]);

        $this->from(route('account.two-factor'))->post(route('two-factor.enable'))->assertRedirect(route('account.two-factor'));
        $admin->refresh();
        $this->assertNotNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->get('/')->assertRedirect(route('account.two-factor'));            // pas encore confirmée
        $this->get(route('account.two-factor'))->assertSee(['Waiting for the first code', '<svg', decrypt($admin->two_factor_secret)], false);

        $this->from(route('account.two-factor'))->post(route('two-factor.confirm'), ['code' => '000000'])
            ->assertSessionHasErrors('code', null, 'confirmTwoFactorAuthentication');
        $this->assertNull($admin->fresh()->two_factor_confirmed_at);

        $this->from(route('account.two-factor'))->post(route('two-factor.confirm'), ['code' => $this->currentCode($admin)])
            ->assertSessionHas('status', 'two-factor-authentication-confirmed');
        $this->assertTrue($admin->fresh()->hasEnabledTwoFactorAuthentication());

        // Codes de secours montrés une fois, puis l'application s'ouvre
        $this->get(route('account.two-factor'))->assertSee(['Your recovery codes', $admin->fresh()->recoveryCodes()[0]]);
        $this->get(route('account.two-factor'))->assertDontSee('Your recovery codes');
        $this->get('/')->assertOk();

        $this->assertSame(['user.2fa_enable'], AuditLog::pluck('action')->all());
    }

    public function test_setup_in_one_step_from_the_page(): void
    {
        $admin = $this->member(TeamRole::Admin);

        $this->actingAs($admin)->from(route('account.two-factor'))->post(route('account.two-factor.enable'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');
        $this->assertNull($admin->fresh()->two_factor_secret);

        $this->from(route('account.two-factor'))->post(route('account.two-factor.enable'), ['password' => self::PASSWORD])
            ->assertRedirect(route('account.two-factor'));
        $admin->refresh();
        $this->assertNotNull($admin->two_factor_secret);
        $this->get(route('account.two-factor'))->assertSee(['<svg', decrypt($admin->two_factor_secret)], false);   // QR tout de suite
    }

    public function test_the_qr_code_needs_a_recent_password_confirmation(): void
    {
        $admin = $this->member(TeamRole::Admin);
        $admin->forceFill(['two_factor_secret' => encrypt(app(Google2FA::class)->generateSecretKey())])->save();

        $this->actingAs($admin)->get(route('account.two-factor'))
            ->assertSee('Confirm your password to see the QR code')
            ->assertDontSee('<svg', false);
    }

    // ─── Connexion ──────────────────────────────────────────────────────

    public function test_login_asks_for_the_code_after_the_password(): void
    {
        $admin = $this->withTwoFactor($this->member(TeamRole::Admin, ['email' => 'admin@company.com']));

        $this->post('/login', ['email' => 'admin@company.com', 'password' => self::PASSWORD])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->get(route('two-factor.login'))->assertOk()->assertSee(['Enter the 6-digit code', 'Use a recovery code']);

        $this->post(route('two-factor.login.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('two-factor.login.store'), ['code' => $this->currentCode($admin)])->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
        $this->assertNotNull($admin->fresh()->last_login_at);
    }

    public function test_a_recovery_code_works_once(): void
    {
        $admin = $this->withTwoFactor($this->member(TeamRole::Admin, ['email' => 'admin@company.com']));

        $this->post('/login', ['email' => 'admin@company.com', 'password' => self::PASSWORD]);
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one'])->assertRedirect('/');
        $this->assertAuthenticatedAs($admin);
        $this->assertNotContains('recovery-one', $admin->fresh()->recoveryCodes());

        $this->post('/logout');
        $this->post('/login', ['email' => 'admin@company.com', 'password' => self::PASSWORD]);
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one'])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_a_deactivated_account_with_two_factor_is_still_refused_at_the_password(): void
    {
        $this->withTwoFactor($this->member(TeamRole::Admin, ['email' => 'gone@company.com', 'deactivated_at' => now()]));

        $this->post('/login', ['email' => 'gone@company.com', 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => 'This account has been deactivated. Contact your administrator.']);
    }

    // ─── Codes de secours, désactivation ────────────────────────────────

    public function test_new_recovery_codes_and_turning_off(): void
    {
        $viewer = $this->withTwoFactor($this->member(TeamRole::Viewer));

        $this->actingAs($viewer)->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('account.two-factor'))->post(route('two-factor.regenerate-recovery-codes'))
            ->assertSessionHas('status', 'recovery-codes-generated');
        $this->assertNotContains('recovery-one', $viewer->fresh()->recoveryCodes());
        $this->get(route('account.two-factor'))->assertSee(['Your recovery codes', 'Turn off']);

        $this->from(route('account.two-factor'))->delete(route('two-factor.disable'));
        $this->assertNull($viewer->fresh()->two_factor_secret);

        $this->assertSame(['user.2fa_recovery_codes', 'user.2fa_disable'], AuditLog::orderBy('id')->pluck('action')->all());
        $this->assertSame('Two-factor disabled', AuditLog::latest('id')->first()->actionLabel());
    }

    public function test_an_admin_does_not_see_the_turn_off_button(): void
    {
        $admin = $this->withTwoFactor($this->member(TeamRole::Admin));

        $this->actingAs($admin)->get(route('account.two-factor'))->assertOk()->assertDontSee('Turn off');
    }

    // ─── Réinitialisation par un Super Admin ────────────────────────────

    public function test_a_super_admin_resets_a_lost_two_factor(): void
    {
        $it = $this->withTwoFactor(User::factory()->superAdmin()->create());
        $admin = $this->withTwoFactor($this->member(TeamRole::Admin, ['name' => 'Boss']));
        $token = $admin->remember_token;

        $this->actingAs($it)->get(route('admin.users.show', $admin))->assertSee('Reset two-factor');
        $this->actingAs($it)->from(route('admin.users.show', $admin))->delete(route('admin.users.two-factor.reset', $admin))
            ->assertSessionHas('success', 'Two-factor authentication of Boss was reset. They will set it up again at their next login.');

        $admin->refresh();
        $this->assertNull($admin->two_factor_secret);
        $this->assertNull($admin->two_factor_recovery_codes);
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertNotSame($token, $admin->remember_token);
        $log = AuditLog::where('action', 'user.2fa_reset')->sole();
        $this->assertSame([$it->id, $admin->id], [$log->user_id, $log->subject_id]);

        // À sa prochaine connexion, il doit la réactiver
        $this->actingAs($admin)->get('/')->assertRedirect(route('account.two-factor'));
        $this->actingAs($it)->get(route('admin.users.show', $admin))->assertDontSee('Reset two-factor');
    }

    public function test_reset_safeguards(): void
    {
        $it = $this->withTwoFactor(User::factory()->superAdmin()->create());
        $viewer = $this->member(TeamRole::Viewer);

        $this->actingAs($it)->from('/')->delete(route('admin.users.two-factor.reset', $it))
            ->assertSessionHas('error', 'Ask another Super Admin to reset your own two-factor authentication.');
        $this->actingAs($it)->from('/')->delete(route('admin.users.two-factor.reset', $viewer))
            ->assertSessionHas('error', "{$viewer->name} has no two-factor authentication to reset.");
        $this->actingAs($this->withTwoFactor($this->member(TeamRole::Admin)))->delete(route('admin.users.two-factor.reset', $it))->assertForbidden();
    }

    public function test_the_admin_list_flags_admins_without_two_factor(): void
    {
        $it = $this->withTwoFactor(User::factory()->superAdmin()->create());
        $this->member(TeamRole::Admin);
        $this->member(TeamRole::Viewer);

        $this->actingAs($it)->get(route('admin.users.index'))->assertOk()
            ->assertSee('Off · required')->assertSee(' On', false);
    }
}
