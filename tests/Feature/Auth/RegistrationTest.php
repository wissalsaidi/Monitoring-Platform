<?php

namespace Tests\Feature\Auth;

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const VALID = [
        'name' => 'Wissal',
        'email' => 'wissal@example.com',
        'password' => 'Str0ng-password',
        'password_confirmation' => 'Str0ng-password',
    ];

    public function test_the_registration_page_is_displayed(): void
    {
        $this->withoutVite();

        $this->get('/register')
            ->assertOk()
            ->assertSee('Create your account')
            ->assertSee('name="_token"', false);
    }

    public function test_new_users_can_register_and_are_logged_in(): void
    {
        $this->post('/register', self::VALID)->assertRedirect('/');

        $this->assertAuthenticatedAs(User::where('email', 'wissal@example.com')->firstOrFail());
    }

    public function test_the_password_is_hashed(): void
    {
        $this->post('/register', self::VALID);

        $user = User::where('email', 'wissal@example.com')->firstOrFail();
        $this->assertNotSame('Str0ng-password', $user->password);
        $this->assertTrue(Hash::check('Str0ng-password', $user->password));
    }

    public function test_a_default_team_is_created_with_the_user_as_admin(): void
    {
        $this->post('/register', self::VALID);

        $user = User::where('email', 'wissal@example.com')->firstOrFail();
        $team = $user->defaultTeam();

        $this->assertNotNull($team);
        $this->assertSame('Default', $team->name);
        $this->assertSame(TeamRole::Admin, $user->roleIn($team));
        $this->assertSame(1, $team->members()->count());
    }

    public function test_each_user_gets_their_own_default_team(): void
    {
        $this->post('/register', self::VALID);
        $this->post('/logout');
        $this->post('/register', [...self::VALID, 'email' => 'other@example.com']);

        $first = User::where('email', 'wissal@example.com')->firstOrFail()->defaultTeam();
        $second = User::where('email', 'other@example.com')->firstOrFail()->defaultTeam();

        $this->assertNotSame($first->id, $second->id);
    }

    public function test_registration_is_refused_with_an_already_used_email(): void
    {
        User::factory()->create(['email' => 'wissal@example.com']);

        $this->post('/register', self::VALID)->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseCount('teams', 0);
    }

    public function test_registration_is_refused_when_passwords_do_not_match(): void
    {
        $this->post('/register', [...self::VALID, 'password_confirmation' => 'something-else'])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseCount('users', 0);
    }
}
