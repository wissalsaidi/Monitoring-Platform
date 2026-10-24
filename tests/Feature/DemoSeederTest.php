<?php

namespace Tests\Feature;

use App\Enums\TargetType;
use App\Enums\TeamRole;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

/**
 * Environnement de démonstration (SCRUM-73) : team « Demo », son compte et une target de chaque type.
 */
class DemoSeederTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeZabbixApi();
        $this->zabbixHosts = [['hostid' => '10700', 'interfaces' => [['interfaceid' => '90', 'type' => '1']]]];
        config([
            'monitoring.demo.email' => 'demo@example.com',
            'monitoring.demo.password' => 'Demo-Pa55word!',
            'monitoring.demo.mysql_monitor_password' => 'monitor-secret',
        ]);
    }

    public function test_it_creates_the_demo_team_its_admin_and_one_target_of_each_type(): void
    {
        $this->seed(DemoSeeder::class);

        $user = User::where('email', 'demo@example.com')->sole();
        $this->assertTrue(Hash::check('Demo-Pa55word!', $user->password));
        $this->assertTrue($user->hasVerifiedEmail());

        $team = Team::where('name', 'Demo')->sole();
        $this->assertSame(TeamRole::Admin, $user->roleIn($team));
        $this->assertTrue($user->defaultTeam()->is($team));

        $targets = $team->monitoringTargets()->orderBy('id')->get();
        $this->assertSame(
            ['Payment API', 'Website-01', 'Server-01', 'Redis-01', 'Nginx-01', 'MySQL-01'],
            $targets->pluck('name')->all(),
        );
        $this->assertEqualsCanonicalizing(
            [TargetType::Api, TargetType::Website, TargetType::Server, TargetType::Container, TargetType::Database],
            $targets->pluck('type')->unique()->values()->all(),
        );
        $this->assertTrue($targets->every(fn (MonitoringTarget $target) => $target->zabbix_host_id !== null && $target->created_by === $user->id));
        $this->assertSame(6, collect($this->calls)->where('method', 'host.create')->count());
    }

    public function test_targets_point_to_the_demo_services(): void
    {
        $this->seed(DemoSeeder::class);

        $targets = MonitoringTarget::all()->keyBy('name');

        $this->assertSame('http://fake-api:8080/health', $targets['Payment API']->url);
        $this->assertSame('http://demo-website-01:8080/', $targets['Website-01']->url);
        $this->assertSame('demo-server-01', $targets['Server-01']->setting('address'));
        $this->assertSame('demo-redis-01', $targets['Redis-01']->setting('container'));
        $this->assertSame('demo-nginx-01', $targets['Nginx-01']->setting('container'));
        $this->assertSame('demo-mysql-01', $targets['MySQL-01']->setting('address'));
    }

    public function test_the_mysql_password_is_stored_encrypted(): void
    {
        $this->seed(DemoSeeder::class);

        $database = MonitoringTarget::where('name', 'MySQL-01')->sole();

        $this->assertSame('monitor-secret', $database->credential('password'));
        $this->assertStringNotContainsString('monitor-secret', (string) $database->getRawOriginal('credentials'));
    }

    public function test_running_it_again_only_creates_missing_targets(): void
    {
        $this->seed(DemoSeeder::class);
        MonitoringTarget::where('name', 'Nginx-01')->delete();
        $this->calls = [];

        $this->seed(DemoSeeder::class);

        $this->assertSame(1, User::where('email', 'demo@example.com')->count());
        $this->assertSame(1, Team::where('name', 'Demo')->count());
        $this->assertSame(6, MonitoringTarget::count());
        $this->assertSame(1, collect($this->calls)->where('method', 'host.create')->count());
    }

    public function test_without_a_configured_password_one_is_generated(): void
    {
        config(['monitoring.demo.password' => null]);

        $this->seed(DemoSeeder::class);

        $this->assertNotNull(User::where('email', 'demo@example.com')->sole()->password);
    }

    public function test_an_invalid_demo_target_is_refused_before_reaching_zabbix(): void
    {
        config(['monitoring.demo.mysql_monitor_password' => '']);

        try {
            $this->seed(DemoSeeder::class);
            $this->fail('The seeder should refuse MySQL-01 without a password.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MySQL-01', $e->getMessage());
        }

        $this->assertSame(5, MonitoringTarget::count());
        $this->assertFalse(MonitoringTarget::where('name', 'MySQL-01')->exists());
    }
}
