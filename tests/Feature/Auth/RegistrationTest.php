<?php

namespace Tests\Feature\Auth;

use App\Enums\RegistrationMode;
use App\Enums\TeamRole;
use App\Mail\NewAccountMail;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Inscription configurable (SCRUM-82) : invite (par défaut), domain, open. Jamais de team créée.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const VALID = [
        'name' => 'Wissal',
        'email' => 'wissal@example.com',
        'password' => 'Str0ng-password',
        'password_confirmation' => 'Str0ng-password',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function mode(string $mode, string $domains = ''): void
    {
        config(['registration.mode' => $mode, 'registration.domains' => $domains]);
    }

    // --- invite (par défaut) ---

    public function test_invite_is_the_default_mode(): void
    {
        config(['registration.mode' => null]);
        $this->assertSame(RegistrationMode::Invite, RegistrationMode::current());

        $this->mode('typo');                         // valeur inconnue : l'inscription reste fermée
        $this->assertSame(RegistrationMode::Invite, RegistrationMode::current());
    }

    public function test_in_invite_mode_the_registration_page_does_not_exist(): void
    {
        $this->mode('invite');

        $this->get('/register')->assertNotFound();
        $this->post('/register', self::VALID)->assertNotFound();

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_in_invite_mode_the_login_page_has_no_sign_up_link(): void
    {
        $this->mode('invite');

        $this->get('/login')->assertOk()
            ->assertDontSee('Create one')
            ->assertSee('Access is by invitation');
    }

    // --- open ---

    public function test_in_open_mode_anyone_can_register_and_is_logged_in(): void
    {
        $this->mode('open');

        $this->get('/login')->assertSee('Create one');
        $this->get('/register')->assertOk()->assertSee('Create your account')->assertSee('name="_token"', false);
        $this->post('/register', self::VALID)->assertRedirect('/');

        $user = User::where('email', 'wissal@example.com')->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame('Str0ng-password', $user->password);
        $this->assertTrue(Hash::check('Str0ng-password', $user->password));
    }

    public function test_no_team_is_created_at_registration(): void
    {
        $this->mode('open');

        $this->post('/register', self::VALID);
        $this->post('/logout');
        $this->post('/register', [...self::VALID, 'email' => 'other@example.com']);

        $this->assertSame(2, User::count());
        $this->assertSame(0, Team::count());
        $this->assertNull(User::where('email', 'wissal@example.com')->sole()->defaultTeam());
    }

    public function test_registration_is_recorded_in_the_audit_log(): void
    {
        $this->mode('open');

        $this->post('/register', self::VALID);

        $log = AuditLog::sole();
        $user = User::sole();
        $this->assertSame('user.create', $log->action);
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->id, $log->subject_id);
        $this->assertNull($log->team_id);
        $this->assertSame(['email' => 'wissal@example.com', 'via' => 'registration', 'mode' => 'open'], $log->details);
    }

    public function test_registration_is_refused_with_an_already_used_email(): void
    {
        $this->mode('open');
        User::factory()->create(['email' => 'wissal@example.com']);

        $this->post('/register', self::VALID)->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_registration_is_refused_when_passwords_do_not_match(): void
    {
        $this->mode('open');

        $this->post('/register', [...self::VALID, 'password_confirmation' => 'something-else'])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    // --- domain ---

    public function test_in_domain_mode_only_the_company_domains_can_register(): void
    {
        $this->mode('domain', 'company.com, @Company.FR');

        $this->get('/register')->assertOk()->assertSee('Use your company address: @company.com, @company.fr');

        $this->post('/register', [...self::VALID, 'email' => 'wissal@gmail.com'])
            ->assertSessionHasErrors(['email' => 'Use your company email address (@company.com, @company.fr).']);
        $this->post('/register', [...self::VALID, 'email' => 'wissal@sub.company.com'])->assertSessionHasErrors('email');
        $this->post('/register', [...self::VALID, 'email' => 'wissal@company.com.evil.io'])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('users', 0);

        $this->post('/register', [...self::VALID, 'email' => 'Wissal@COMPANY.fr'])->assertRedirect('/');
        $this->assertSame(0, Team::count());
        $this->assertSame('domain', AuditLog::sole()->details['mode']);
    }

    public function test_domain_mode_without_domains_accepts_nobody(): void
    {
        $this->mode('domain');

        $this->post('/register', self::VALID)->assertSessionHasErrors('email');

        $this->assertDatabaseCount('users', 0);
    }

    // --- « Waiting for access » ---

    public function test_a_user_without_team_waits_for_access(): void
    {
        config(['registration.contact' => 'it-support@company.com']);
        $user = User::factory()->create(['email' => 'new@company.com']);

        foreach (['/', '/targets', '/alerts', '/notifications', '/audit'] as $page) {
            $this->actingAs($user)->get($page)->assertRedirect(route('access.waiting'));
        }

        $this->actingAs($user)->get(route('access.waiting'))->assertOk()
            ->assertSee(['Waiting for access', 'new@company.com', 'mailto:it-support@company.com'], false)
            ->assertDontSee('You are a Super Admin');
        $this->actingAs($user)->get(route('account.edit'))->assertOk();
    }

    public function test_the_waiting_page_sends_a_member_to_the_dashboard(): void
    {
        $user = User::factory()->withTeam(TeamRole::Viewer)->create();

        $this->actingAs($user)->get(route('access.waiting'))->assertRedirect(route('dashboard'));
        $this->actingAs($user)->get('/')->assertOk();
    }

    public function test_a_super_admin_without_team_is_told_why(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())->get(route('access.waiting'))
            ->assertOk()->assertSee('You are a Super Admin')->assertSee('your administrator');
    }

    // --- Super Admin prévenus ---

    public function test_super_admins_are_emailed_once_the_new_account_is_verified(): void
    {
        Mail::fake();
        $this->mode('domain', 'company.com');
        $admins = User::factory()->superAdmin()->count(2)->create();
        User::factory()->create();                                 // pas Super Admin : rien

        $this->post('/register', [...self::VALID, 'email' => 'new@company.com']);
        $user = User::where('email', 'new@company.com')->sole();
        Mail::assertNothingQueued();                               // pas avant la vérification de l'email

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($url);

        Mail::assertQueuedCount(2);
        foreach ($admins as $admin) {
            Mail::assertQueued(NewAccountMail::class, fn (NewAccountMail $mail) => $mail->hasTo($admin->email) && $mail->account->is($user));
        }
        $this->assertStringContainsString('new@company.com', (new NewAccountMail($user))->render());
    }

    public function test_no_email_when_the_verified_account_already_has_a_team(): void
    {
        Mail::fake();
        User::factory()->superAdmin()->create();
        $user = User::factory()->unverified()->withTeam()->create();

        $url = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->actingAs($user)->get($url);

        Mail::assertNothingQueued();
    }
}
