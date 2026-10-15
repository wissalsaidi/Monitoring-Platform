<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Rules\PublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

class SsrfProtectionTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeZabbixApi();
        $team = Team::factory()->create();
        $this->manager = User::factory()->create();
        $team->members()->attach($this->manager, ['role' => TeamRole::Manager]);
    }

    private function create(string $url)
    {
        return $this->actingAs($this->manager)->post('/targets', [
            'type' => 'api',
            'name' => 'Target '.md5($url),
            'url' => $url,
            'check_interval' => 30,
            'warning_threshold_ms' => 500,
            'critical_threshold_ms' => 1000,
        ]);
    }

    public static function internalUrls(): array
    {
        return [
            'localhost' => ['http://localhost/health'],
            'sous-domaine localhost' => ['http://api.localhost/health'],
            'loopback 127.0.0.1' => ['http://127.0.0.1:8000/'],
            'loopback 127.8.8.8' => ['http://127.8.8.8/'],
            'réseau 10/8' => ['http://10.0.0.5/'],
            'réseau 172.16/12' => ['http://172.20.0.3:3306/'],
            'réseau 192.168/16' => ['http://192.168.1.1/admin'],
            'link-local (métadonnées cloud)' => ['http://169.254.169.254/latest/meta-data/'],
            'CGNAT 100.64/10' => ['http://100.64.0.1/'],
            '0.0.0.0' => ['http://0.0.0.0/'],
            'IPv6 loopback' => ['http://[::1]/'],
            'IPv6 unique local fc00::/7' => ['http://[fd00::1]/'],
            'IPv6 link-local' => ['http://[fe80::1]/'],
            'IPv4 dans IPv6' => ['http://[::ffff:127.0.0.1]/'],
        ];
    }

    #[DataProvider('internalUrls')]
    public function test_internal_addresses_are_refused(string $url): void
    {
        $this->create($url)->assertSessionHasErrors(['url' => PublicUrl::MESSAGE]);

        $this->assertSame(0, MonitoringTarget::count());
        $this->assertSame([], $this->callsTo('host.create'));
    }

    public function test_a_public_name_resolving_to_an_internal_ip_is_refused(): void
    {
        // Un nom public qui pointe vers le réseau interne (ou un service Docker comme « mysql »)
        $this->dnsRecords['innocent.example.com'] = ['10.1.2.3'];
        $this->dnsRecords['mysql'] = ['172.18.0.4'];

        $this->create('https://innocent.example.com/health')->assertSessionHasErrors(['url' => PublicUrl::MESSAGE]);
        $this->create('http://mysql:3306/')->assertSessionHasErrors(['url' => PublicUrl::MESSAGE]);
    }

    public function test_a_name_with_one_internal_ip_among_public_ones_is_refused(): void
    {
        $this->dnsRecords['mixed.example.com'] = ['93.184.216.34', '127.0.0.1'];

        $this->create('https://mixed.example.com/')->assertSessionHasErrors(['url' => PublicUrl::MESSAGE]);
    }

    public function test_an_unknown_host_is_refused(): void
    {
        $this->dnsRecords['does-not-exist.example'] = [];

        $this->create('https://does-not-exist.example/')->assertSessionHasErrors(['url' => PublicUrl::UNRESOLVED]);
    }

    public function test_a_public_address_is_accepted(): void
    {
        $this->create('https://api.example.com/health')->assertSessionHasNoErrors();
        $this->create('http://8.8.8.8/')->assertSessionHasNoErrors();

        $this->assertSame(2, MonitoringTarget::count());
    }

    public function test_allowed_internal_hosts_are_accepted(): void
    {
        $this->dnsRecords['fake-api'] = ['172.18.0.9'];

        $this->create('http://fake-api:8080/health')->assertSessionHasNoErrors();
    }

    public function test_the_allowlist_comes_from_the_configuration(): void
    {
        config(['monitoring.allowed_internal_hosts' => []]);
        $this->dnsRecords['fake-api'] = ['172.18.0.9'];

        $this->create('http://fake-api:8080/health')->assertSessionHasErrors(['url' => PublicUrl::MESSAGE]);
    }

    public function test_the_rule_also_protects_the_edit_form(): void
    {
        $target = MonitoringTarget::factory()->for($this->manager->teams()->first())->create(['zabbix_host_id' => '10684']);

        $this->actingAs($this->manager)
            ->put(route('targets.update', $target), [
                'name' => $target->name,
                'url' => 'http://169.254.169.254/latest/meta-data/',
                'check_interval' => 30,
                'warning_threshold_ms' => 500,
                'critical_threshold_ms' => 1000,
            ])
            ->assertSessionHasErrors(['url' => PublicUrl::MESSAGE]);

        $this->assertSame([], $this->calls);
    }

    public function test_an_invalid_url_gets_only_the_url_format_error(): void
    {
        $this->create('not a url')->assertSessionHasErrors('url');

        $this->assertNotContains(PublicUrl::MESSAGE, session('errors')->get('url'));
    }
}
