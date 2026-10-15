<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Notification::fake();
    }

    public function test_the_login_page_links_to_the_forgot_password_page(): void
    {
        $this->get('/login')->assertSee(route('password.request'));
        $this->get('/forgot-password')->assertOk()->assertSee('Forgot your password?');
    }

    public function test_a_reset_link_is_emailed(): void
    {
        $user = User::factory()->create(['email' => 'wissal@example.com']);

        $this->post('/forgot-password', ['email' => 'wissal@example.com'])
            ->assertSessionHas('status', 'We have emailed your password reset link.');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_an_unknown_email_gets_an_error_and_no_mail(): void
    {
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_a_second_request_within_a_minute_is_throttled(): void
    {
        $user = User::factory()->create();

        $this->post('/forgot-password', ['email' => $user->email]);
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasErrors('email');

        Notification::assertSentToTimes($user, ResetPassword::class, 1);
    }

    public function test_the_link_opens_the_reset_form(): void
    {
        $user = User::factory()->create();
        $this->post('/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $this->get('/reset-password/'.$notification->token.'?email='.urlencode($user->email))
                ->assertOk()
                ->assertSee(['Choose a new password', $user->email]);

            return true;
        });
    }

    public function test_the_password_can_be_reset_with_a_valid_token(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'N3w-Passw0rd!',
            'password_confirmation' => 'N3w-Passw0rd!',
        ])->assertRedirect('/login')->assertSessionHas('status', 'Your password has been reset.');

        $this->assertTrue(Hash::check('N3w-Passw0rd!', $user->fresh()->password));
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->post('/reset-password', [
            'token' => 'forged-token',
            'email' => $user->email,
            'password' => 'N3w-Passw0rd!',
            'password_confirmation' => 'N3w-Passw0rd!',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(Hash::check('N3w-Passw0rd!', $user->fresh()->password));
    }

    public function test_the_token_expires_after_60_minutes(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->travel(61)->minutes();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'N3w-Passw0rd!',
            'password_confirmation' => 'N3w-Passw0rd!',
        ])->assertSessionHasErrors('email');
    }
}
