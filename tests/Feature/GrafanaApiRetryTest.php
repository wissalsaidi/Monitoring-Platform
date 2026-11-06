<?php

namespace Tests\Feature;

use App\Exceptions\GrafanaException;
use App\Services\GrafanaApi;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * Erreur 5xx passagère de Grafana (SCRUM-85, vue en vrai : « database is locked » au renommage d'une organisation) :
 * nouvel essai pour les requêtes idempotentes seulement.
 */
class GrafanaApiRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config(['services.grafana.internal_url' => 'http://grafana.test/grafana', 'services.grafana.admin_password' => 'secret']);
    }

    public function test_a_put_is_retried_after_a_transient_server_error(): void
    {
        Http::fake(['grafana.test/*' => Http::sequence()
            ->push(['message' => 'Failed to update organization'], 500)
            ->push(['message' => 'Organization updated'], 200)]);

        app(GrafanaApi::class)->renameOrg(6, 'Quality #12');

        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request['name'] === 'Quality #12');
    }

    public function test_a_put_gives_up_after_three_attempts(): void
    {
        Http::fake(['grafana.test/*' => Http::response(['message' => 'Failed to update organization'], 500)]);

        try {
            app(GrafanaApi::class)->renameOrg(6, 'Quality #12');
            $this->fail('GrafanaException expected');
        } catch (GrafanaException $e) {
            $this->assertStringContainsString('HTTP 500 Failed to update organization', $e->getMessage());
        }

        Http::assertSentCount(GrafanaApi::RETRIES);
    }

    public function test_a_post_is_never_replayed(): void
    {
        Http::fake(['grafana.test/*' => Http::response(['message' => 'Failed to create organization'], 500)]);

        $this->expectException(GrafanaException::class);
        try {
            app(GrafanaApi::class)->createOrg('Ops #3');
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_a_client_error_is_not_retried(): void
    {
        Http::fake(['grafana.test/*' => Http::response(['message' => 'Name taken'], 409)]);

        $this->expectException(GrafanaException::class);
        try {
            app(GrafanaApi::class)->renameOrg(6, 'Ops #3');
        } finally {
            Http::assertSentCount(1);
        }
    }
}
