<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    private function verificationUrl(User $user, int $minutes = 60): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), [
            'id' => $user->id,
            'hash' => sha1($user->email),
        ]);
    }

    public function test_registration_sends_a_verification_email(): void
    {
        config(['registration.mode' => 'open']);
        $this->post('/register', [
            'name' => 'Wissal',
            'email' => 'wissal@example.com',
            'password' => 'Passw0rd!2026',
            'password_confirmation' => 'Passw0rd!2026',
        ]);

        Notification::assertSentTo(User::sole(), VerifyEmail::class);
    }

    public function test_an_unverified_user_cannot_reach_the_application(): void
    {
        $user = User::factory()->unverified()->create();

        foreach (['/', '/targets', '/alerts'] as $page) {
            $this->actingAs($user)->get($page)->assertRedirect('/email/verify');
        }

        $this->actingAs($user)->get('/email/verify')->assertOk()->assertSee(['Verify your email', $user->email]);
    }

    public function test_a_verified_user_reaches_the_dashboard(): void
    {
        $this->actingAs(User::factory()->withTeam()->create())->get('/')->assertOk();
    }

    public function test_the_signed_link_verifies_the_email(): void
    {
        $user = User::factory()->unverified()->withTeam()->create();

        $this->actingAs($user)->get($this->verificationUrl($user))->assertRedirect('/?verified=1');

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->actingAs($user->fresh())->get('/')->assertOk();
    }

    public function test_the_email_link_is_a_signed_url_valid_60_minutes(): void
    {
        $user = User::factory()->unverified()->create();
        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;
            parse_str(parse_url($url, PHP_URL_QUERY), $query);

            $this->assertArrayHasKey('signature', $query);
            $this->assertEqualsWithDelta(now()->addMinutes(60)->timestamp, (int) $query['expires'], 2);

            return true;
        });
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $url = $this->verificationUrl($user);

        $this->travel(61)->minutes();

        $this->actingAs($user)->get($url)->assertForbidden();
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
    }

    public function test_a_tampered_link_is_rejected(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();
        $url = str_replace('/'.$user->id.'/', '/'.$other->id.'/', $this->verificationUrl($user));

        $this->actingAs($other)->get($url)->assertForbidden();
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_the_link_of_another_user_does_not_verify_me(): void
    {
        $user = User::factory()->unverified()->create();
        $other = User::factory()->unverified()->create();

        $this->actingAs($other)->get($this->verificationUrl($user))->assertForbidden();
        $this->assertFalse($other->fresh()->hasVerifiedEmail());
    }

    public function test_the_verification_email_can_be_resent(): void
    {
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->from('/email/verify')
            ->post('/email/verification-notification')
            ->assertRedirect('/email/verify')
            ->assertSessionHas('status', 'verification-link-sent');

        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
