<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamTargetVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private function memberOf(Team $team, TeamRole $role = TeamRole::Viewer): User
    {
        $user = User::factory()->create();
        $team->members()->attach($user, ['role' => $role]);

        return $user;
    }

    public function test_a_user_only_sees_the_targets_of_their_teams(): void
    {
        [$mine, $other] = Team::factory()->count(2)->create();
        $user = $this->memberOf($mine);
        $visible = MonitoringTarget::factory()->count(2)->for($mine)->create();
        MonitoringTarget::factory()->for($other)->create();

        $this->assertEqualsCanonicalizing(
            $visible->modelKeys(),
            MonitoringTarget::visibleTo($user)->pluck('id')->all(),
        );
    }

    public function test_a_member_of_several_teams_sees_the_targets_of_all_of_them(): void
    {
        [$a, $b, $c] = Team::factory()->count(3)->create();
        $user = $this->memberOf($a);
        $b->members()->attach($user, ['role' => TeamRole::Manager]);
        MonitoringTarget::factory()->for($a)->create();
        MonitoringTarget::factory()->for($b)->create();
        MonitoringTarget::factory()->for($c)->create();

        $this->assertSame(2, MonitoringTarget::visibleTo($user)->count());
    }

    public function test_a_user_without_team_sees_nothing(): void
    {
        MonitoringTarget::factory()->create();

        $this->assertSame(0, MonitoringTarget::visibleTo(User::factory()->create())->count());
    }

    public function test_the_policy_allows_viewing_only_targets_of_the_user_teams(): void
    {
        [$mine, $other] = Team::factory()->count(2)->create();
        $user = $this->memberOf($mine);

        $this->assertTrue($user->can('view', MonitoringTarget::factory()->for($mine)->create()));
        $this->assertFalse($user->can('view', MonitoringTarget::factory()->for($other)->create()));
    }

    public function test_the_member_role_is_stored_on_the_membership(): void
    {
        $team = Team::factory()->create();
        $user = $this->memberOf($team, TeamRole::Manager);

        $this->assertSame(TeamRole::Manager, $user->roleIn($team));
        $this->assertNull($user->roleIn(Team::factory()->create()));
    }
}
