<?php

namespace Tests\Feature;

use App\Enums\AlertSeverity;
use App\Enums\TargetType;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;
use App\Monitoring\Provisioners\WebsiteProvisioner;
use App\Services\AlertService;
use App\Services\MonitoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

/**
 * Site web et certificat SSL (SCRUM-68).
 */
class WebsiteTargetTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private Team $team;

    private const SITE = [
        'name' => 'Shop',
        'url' => 'https://shop.example.com/',
        'check_interval' => 60,
        'warning_threshold_ms' => 800,
        'critical_threshold_ms' => 2000,
    ];

    /** Les 3 triggers du check HTTP + les 2 du certificat, tels que trigger.get les renvoie */
    private const ALL_TRIGGERS = [
        ['triggerid' => '500', 'tags' => [['tag' => 'level', 'value' => 'unavailable']]],
        ['triggerid' => '501', 'tags' => [['tag' => 'level', 'value' => 'critical']]],
        ['triggerid' => '502', 'tags' => [['tag' => 'level', 'value' => 'warning']]],
        ['triggerid' => '503', 'tags' => [['tag' => 'level', 'value' => 'certificate_critical']]],
        ['triggerid' => '504', 'tags' => [['tag' => 'level', 'value' => 'certificate_warning']]],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->fakeZabbixApi();
        $this->team = Team::factory()->create(['zabbix_hostgroup_id' => '42']);
        // Interface agent du host créé, lue avant de créer l'item du certificat
        $this->zabbixHosts = [['hostid' => '10700', 'interfaces' => [['interfaceid' => '90', 'type' => '1']]]];
    }

    private function createSite(array $overrides = []): MonitoringTarget
    {
        return app(MonitoringService::class)->createTarget($this->team, null, TargetType::Website, [...self::SITE, ...$overrides]);
    }

    private function member(TeamRole $role = TeamRole::Manager): User
    {
        $user = User::factory()->create();
        $this->team->members()->attach($user, ['role' => $role]);

        return $user;
    }

    /** Target site web déjà provisionnée (host 10700) */
    private function existingSite(array $attributes = []): MonitoringTarget
    {
        return MonitoringTarget::factory()->for($this->team)->create([
            'type' => TargetType::Website, ...self::SITE, 'zabbix_host_id' => '10700', ...$attributes,
        ]);
    }

    public function test_the_website_type_is_offered_in_the_form(): void
    {
        $html = $this->actingAs($this->member())->get(route('targets.create'))->assertOk()->getContent();

        // La balise <input> du type, attributs compris (ils sont sur plusieurs lignes)
        preg_match('/<input[^>]*id="type-website"[^>]*>/s', $html, $website);
        preg_match('/<input[^>]*id="type-container"[^>]*>/s', $html, $container);
        $this->assertStringNotContainsString('disabled', $website[0]);
        $this->assertStringContainsString('disabled', $container[0]);
    }

    public function test_a_manager_creates_a_website(): void
    {
        $this->actingAs($this->member())->post(route('targets.store'), ['type' => 'website', ...self::SITE])
            ->assertSessionHasNoErrors();

        $target = MonitoringTarget::sole();
        $this->assertSame(TargetType::Website, $target->type);
        $this->assertSame('https://shop.example.com/', $target->url);
        $this->assertSame('10700', $target->zabbix_host_id);
    }

    public function test_internal_addresses_are_refused_like_for_apis(): void
    {
        $this->dnsRecords['intranet.local'] = ['10.0.0.5'];

        $this->actingAs($this->member())->post(route('targets.store'), ['type' => 'website', ...self::SITE, 'url' => 'https://intranet.local/'])
            ->assertSessionHasErrors('url');

        $this->assertDatabaseCount('monitoring_targets', 0);
        $this->assertSame([], $this->callsTo('host.create'));
    }

    public function test_the_http_check_uses_its_own_web_scenario(): void
    {
        $this->createSite();

        $scenario = $this->callsTo('httptest.create')[0];
        $this->assertSame('website-check', $scenario['name']);
        $this->assertSame('page', $scenario['steps'][0]['name']);
        $this->assertSame('https://shop.example.com/', $scenario['steps'][0]['url']);
        $this->assertSame('60s', $scenario['delay']);

        $triggers = collect($this->callsTo('trigger.create'))->keyBy(fn ($t) => collect($t['tags'])->firstWhere('tag', 'level')['value']);
        $this->assertSame('last(/target-1/web.test.fail[website-check])<>0', $triggers['unavailable']['expression']);
        $this->assertSame('last(/target-1/web.test.time[website-check,page,resp])>2', $triggers['critical']['expression']);
        $this->assertSame('last(/target-1/web.test.time[website-check,page,resp])>0.8', $triggers['warning']['expression']);
    }

    public function test_an_https_website_gets_the_certificate_check_through_the_agent(): void
    {
        $this->createSite();

        // Host avec interface agent (zabbix-agent2, SCRUM-67)
        $this->assertSame('zabbix-agent2', $this->callsTo('host.create')[0]['interfaces'][0]['dns']);

        [$certificate, $days] = $this->callsTo('item.create');
        $this->assertSame('web.certificate.get[shop.example.com,443]', $certificate['key_']);
        $this->assertSame(0, $certificate['type']);             // Zabbix agent
        $this->assertSame('90', $certificate['interfaceid']);
        $this->assertSame('1h', $certificate['delay']);

        $this->assertSame(WebsiteProvisioner::CERTIFICATE_DAYS_KEY, $days['key_']);
        $this->assertSame(18, $days['type']);                   // item dépendant
        $this->assertSame('800', $days['master_itemid']);
        $this->assertSame('$.x509.not_after.timestamp', $days['preprocessing'][0]['params']);

        // Premier relevé demandé tout de suite
        $this->assertSame([[['type' => 6, 'request' => ['itemid' => '800']]]], $this->callsTo('task.create'));
    }

    public function test_certificate_triggers_form_their_own_chain(): void
    {
        $this->createSite();

        $triggers = $this->callsTo('trigger.create');
        $this->assertCount(5, $triggers);
        [, , , $critical, $warning] = $triggers;

        $this->assertSame('last(/target-1/website.certificate.days)<3', $critical['expression']);
        $this->assertSame(4, $critical['priority']);            // HIGH
        $this->assertArrayNotHasKey('dependencies', $critical); // indépendant de la disponibilité du site
        $this->assertArrayNotHasKey('chain', $critical);        // clé interne, jamais envoyée à Zabbix

        $this->assertSame('last(/target-1/website.certificate.days)<14', $warning['expression']);
        $this->assertSame(2, $warning['priority']);             // WARNING
        $this->assertSame([['triggerid' => '503']], $warning['dependencies']);
        $this->assertContains(['tag' => 'metric', 'value' => 'certificate'], $warning['tags']);
    }

    public function test_the_certificate_port_comes_from_the_url(): void
    {
        $this->createSite(['url' => 'https://Shop.Example.com:8443/home']);

        $this->assertSame('web.certificate.get[shop.example.com,8443]', $this->callsTo('item.create')[0]['key_']);
    }

    public function test_an_http_website_has_no_certificate_check(): void
    {
        $this->createSite(['url' => 'http://shop.example.com/']);

        $this->assertSame([], $this->callsTo('item.create'));
        $this->assertCount(3, $this->callsTo('trigger.create'));
        $this->assertSame([], $this->callsTo('task.create'));
    }

    public function test_a_failed_first_certificate_check_request_does_not_block_the_creation(): void
    {
        $this->failOn('task.create');

        $target = $this->createSite();

        $this->assertSame('10700', $target->zabbix_host_id);
        $this->assertSame([], $this->callsTo('host.delete'));
    }

    public function test_switching_to_https_adds_the_certificate_check(): void
    {
        $target = $this->existingSite(['url' => 'http://shop.example.com/']);
        $this->zabbixItems = [];                       // pas encore d'item de certificat
        $this->zabbixTriggers = self::ALL_TRIGGERS;    // trigger.get après création

        app(MonitoringService::class)->updateTarget($target, ['url' => 'https://shop.example.com/']);

        $this->assertSame('web.certificate.get[shop.example.com,443]', $this->callsTo('item.create')[0]['key_']);
        $this->assertSame(['certificate_critical', 'certificate_warning'], array_map(
            fn ($t) => collect($t['tags'])->firstWhere('tag', 'level')['value'],
            $this->callsTo('trigger.create'),
        ));
        $this->assertCount(5, $this->callsTo('trigger.update')[0]);
    }

    public function test_switching_back_to_http_deletes_the_certificate_check(): void
    {
        $target = $this->existingSite();
        $this->zabbixItems = [['itemid' => '800', 'key_' => 'web.certificate.get[shop.example.com,443]']];

        app(MonitoringService::class)->updateTarget($target, ['url' => 'http://shop.example.com/']);

        $this->assertSame([['800']], $this->callsTo('item.delete'));
        $this->assertCount(3, $this->callsTo('trigger.update')[0]);
    }

    public function test_a_new_host_name_updates_the_certificate_item(): void
    {
        $target = $this->existingSite();
        $this->zabbixItems = [['itemid' => '800', 'key_' => 'web.certificate.get[shop.example.com,443]']];
        $this->zabbixTriggers = self::ALL_TRIGGERS;

        app(MonitoringService::class)->updateTarget($target, ['url' => 'https://store.example.com/']);

        $this->assertSame([[['itemid' => '800', 'key_' => 'web.certificate.get[store.example.com,443]']]], $this->callsTo('item.update'));
        $this->assertSame([], $this->callsTo('item.create'));
    }

    public function test_the_sync_records_certificate_alerts_and_metrics(): void
    {
        $target = $this->existingSite();
        $this->zabbixItems = [
            ['itemid' => '1', 'hostid' => '10700', 'key_' => 'web.test.fail[website-check]', 'lastclock' => '1791809000', 'lastvalue' => '0'],
            ['itemid' => '2', 'hostid' => '10700', 'key_' => 'web.test.time[website-check,page,resp]', 'lastclock' => '1791809000', 'lastvalue' => '0.31'],
            ['itemid' => '3', 'hostid' => '10700', 'key_' => 'web.test.rspcode[website-check,page]', 'lastclock' => '1791809000', 'lastvalue' => '200'],
            ['itemid' => '4', 'hostid' => '10700', 'key_' => 'website.certificate.days', 'lastclock' => '1791809000', 'lastvalue' => '9'],
        ];
        $this->zabbixProblems = [[
            'eventid' => '9200', 'objectid' => '504', 'clock' => '1791809040', 'r_eventid' => '0', 'r_clock' => '0',
            'severity' => '2', 'name' => 'Shop SSL certificate expires in less than 14 days', 'acknowledged' => '0',
            'tags' => [['tag' => 'target_id', 'value' => (string) $target->id], ['tag' => 'metric', 'value' => 'certificate'], ['tag' => 'level', 'value' => 'certificate_warning']],
        ]];

        app(AlertService::class)->syncFromZabbix();

        $alert = Alert::sole();
        $this->assertSame('certificate', $alert->metric);
        $this->assertSame(14, $alert->threshold);
        $this->assertSame(AlertSeverity::Warning, $alert->severity);
        $this->assertNull($alert->value);
        $this->assertSame('< 14 days', $alert->thresholdLabel());
        $this->assertSame('SSL certificate', $alert->metricLabel());

        $target->refresh();
        $this->assertSame(['http_code' => 200, 'certificate_days' => 9], $target->last_metrics);
        $this->assertSame(310.0, $target->last_response_ms);
    }

    public function test_the_target_page_shows_http_code_and_certificate(): void
    {
        $target = $this->existingSite(['last_metrics' => ['http_code' => 200, 'certificate_days' => 9]]);

        $this->actingAs($this->member(TeamRole::Viewer))->get(route('targets.show', $target))
            ->assertOk()
            ->assertSeeInOrder(['HTTP code', '200', 'SSL certificate', '9', 'days left']);
    }

    public function test_an_expired_certificate_is_shown_as_expired(): void
    {
        $target = $this->existingSite(['last_metrics' => ['http_code' => 200, 'certificate_days' => -4]]);

        $this->actingAs($this->member(TeamRole::Viewer))->get(route('targets.show', $target))
            ->assertOk()
            ->assertSee(['Expired', '4 days ago']);
    }

    public function test_the_configuration_tab_describes_the_checks(): void
    {
        $target = $this->existingSite(['url' => 'https://shop.example.com:8443/']);

        $this->actingAs($this->member(TeamRole::Viewer))->get(route('targets.show', [$target, 'tab' => 'configuration']))
            ->assertOk()
            ->assertSee(['Web scenario &quot;website-check&quot;', 'SSL certificate of shop.example.com:8443'], false);
    }

    public function test_the_response_time_chart_reads_the_website_item(): void
    {
        $target = $this->existingSite();

        $this->actingAs($this->member(TeamRole::Viewer))->getJson(route('targets.metrics.response-time', $target))->assertOk();

        $this->assertSame(['web.test.time[website-check,page,resp]'], $this->callsTo('item.get')[0]['filter']['key_']);
    }
}
