<?php

namespace Database\Seeders;

use App\Enums\TargetType;
use App\Enums\TeamRole;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\ProvisionerRegistry;
use App\Services\MonitoringService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Environnement de démonstration (SCRUM-73, cahier des charges §42) : la team « Demo », son administrateur et une
 * target de chaque type, pointant vers les services du profil "demo" du docker-compose.
 *
 *   docker compose --profile demo up -d
 *   docker compose exec -u www-data app php artisan db:seed --class=DemoSeeder
 *
 * Les targets sont créées comme depuis le formulaire : mêmes règles de validation, host et triggers créés dans Zabbix.
 * Relancer le seeder ne crée que les targets manquantes. Seuils bas : les pannes de docs/demo/README.md les dépassent vite.
 */
class DemoSeeder extends Seeder
{
    public const TEAM_NAME = 'Demo';

    public function __construct(
        private readonly MonitoringService $monitoring,
        private readonly ProvisionerRegistry $provisioners,
    ) {}

    public function run(): void
    {
        $user = $this->demoUser();
        $team = $this->demoTeam($user);

        foreach ($this->targets() as $target) {
            if ($team->monitoringTargets()->where('name', $target['name'])->exists()) {
                $this->command?->line("  {$target['name']} : already there");

                continue;
            }

            $type = $target['type'];
            $this->monitoring->createTarget($team, $user, $type, $this->validated($type, $target));
            $this->command?->info("  {$target['name']} ({$type->label()}) created");
        }
    }

    /**
     * Targets de démo : Payment API (fake API, SCRUM-29), Website-01, Server-01, Redis-01, Nginx-01 et MySQL-01.
     *
     * @return list<array<string, mixed>>
     */
    public function targets(): array
    {
        $agent = ['address' => config('monitoring.agent.host'), 'port' => config('monitoring.agent.port')];

        return [
            [
                'type' => TargetType::Api, 'name' => 'Payment API', 'check_interval' => 60,
                'description' => 'Fake payment API (fake-api service): slow it down or make it fail with /control.',
                'url' => 'http://fake-api:8080/health', 'warning_threshold_ms' => 500, 'critical_threshold_ms' => 1000,
            ],
            [
                'type' => TargetType::Website, 'name' => 'Website-01', 'check_interval' => 60,
                'description' => 'Demo shop (demo-website-01 service): slow it down with /control?delay=2500.',
                'url' => 'http://demo-website-01:8080/', 'warning_threshold_ms' => 800, 'critical_threshold_ms' => 2000,
            ],
            [
                'type' => TargetType::Server, 'name' => 'Server-01', 'check_interval' => 60,
                'description' => 'Linux server (demo-server-01 agent). Its / disk is a 64 MB tmpfs that can be filled safely.',
                'settings' => [
                    'address' => 'demo-server-01', 'port' => 10050,
                    'cpu_warning' => 60, 'cpu_critical' => 85,
                    'memory_warning' => 85, 'memory_critical' => 95,
                    'disk_warning' => 70, 'disk_critical' => 90,
                ],
            ],
            [
                'type' => TargetType::Container, 'name' => 'Redis-01', 'check_interval' => 60,
                'description' => 'Redis cache container (demo-redis-01).',
                'settings' => ['container' => 'demo-redis-01', ...$agent, 'memory_warning' => 64],
            ],
            [
                'type' => TargetType::Container, 'name' => 'Nginx-01', 'check_interval' => 60,
                'description' => 'Nginx web server container (demo-nginx-01).',
                'settings' => ['container' => 'demo-nginx-01', ...$agent, 'memory_warning' => 64],
            ],
            [
                'type' => TargetType::Database, 'name' => 'MySQL-01', 'check_interval' => 60,
                'description' => 'MySQL database of the demo shop (demo-mysql-01), read by the zbx_monitor user.',
                'settings' => ['address' => 'demo-mysql-01', 'port' => 3306, 'username' => 'zbx_monitor', 'connections_warning' => 20],
                'credentials' => ['password' => (string) config('monitoring.demo.mysql_monitor_password')],
            ],
        ];
    }

    /** Compte de démo (email vérifié). Mot de passe : DEMO_USER_PASSWORD, sinon généré et affiché une fois. */
    private function demoUser(): User
    {
        $email = config('monitoring.demo.email');
        $user = User::where('email', $email)->first();

        if ($user !== null) {
            $this->command?->line("Demo user {$email} : already there (password unchanged)");

            return $user;
        }

        $password = config('monitoring.demo.password') ?: Str::password(16);
        $user = User::forceCreate([
            'name' => 'Demo',
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
        ]);

        $this->command?->info(config('monitoring.demo.password')
            ? "Demo user {$email} created (password: DEMO_USER_PASSWORD)"
            : "Demo user {$email} created, password: {$password}");

        return $user;
    }

    /** Team « Demo », dont le compte de démo est administrateur : c'est sa team par défaut si c'est sa seule team. */
    private function demoTeam(User $user): Team
    {
        $team = $user->teams()->where('name', self::TEAM_NAME)->first()
            ?? tap(Team::create(['name' => self::TEAM_NAME]), fn (Team $team) => $team->members()->attach($user, ['role' => TeamRole::Admin]));

        return $team;
    }

    /** Mêmes règles que le formulaire de création (champs communs + règles du type), sans le type. */
    private function validated(TargetType $type, array $target): array
    {
        $validator = Validator::make($target, [
            'name' => ['required', 'string', 'max:100'],
            'check_interval' => ['required', 'integer', 'in:'.implode(',', array_keys(MonitoringTarget::CHECK_INTERVALS))],
            'description' => ['nullable', 'string', 'max:500'],
            ...$this->provisioners->for($type)->rules(),
        ]);

        if ($validator->fails()) {
            throw new RuntimeException("Demo target {$target['name']} is invalid: ".implode(' ', $validator->errors()->all()));
        }

        return $validator->validated();
    }
}
