<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * php artisan security:check (SCRUM-61).
 */
class SecurityCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    private function productionConfig(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.url' => 'https://monitoring.example.com',
            'session.secure' => true,
            'session.http_only' => true,
            'security.hsts_max_age' => 31536000,
            'logging.default' => 'stack',
            'logging.channels.stack.channels' => ['daily'],
            'logging.channels.daily.level' => 'warning',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.com',
        ]);
    }

    public function test_a_production_configuration_passes(): void
    {
        $this->productionConfig();

        $this->artisan('security:check')
            ->expectsOutputToContain('Configuration prête pour la production.')
            ->assertSuccessful();
    }

    public function test_debug_mode_fails_the_check(): void
    {
        $this->productionConfig();
        config(['app.debug' => true]);

        $this->artisan('security:check')
            ->expectsOutputToContain('1 point(s) à corriger')
            ->assertFailed();
    }

    public function test_the_development_configuration_fails(): void
    {
        $this->productionConfig();
        config(['app.url' => 'http://localhost:8000', 'session.secure' => null, 'mail.mailers.smtp.host' => 'mailpit']);

        $this->artisan('security:check')
            ->expectsOutputToContain('3 point(s) à corriger')
            ->assertFailed();
    }

    /** SSO obligatoire (SCRUM-90) : fournisseur configuré et compte de secours */
    public function test_sso_required_needs_a_provider_and_an_emergency_account(): void
    {
        $this->productionConfig();
        config(['oidc.required' => true, 'oidc.issuer' => '']);

        $this->artisan('security:check')->expectsOutputToContain('2 point(s) à corriger')->assertFailed();

        config(['oidc.issuer' => 'https://sso.company.com', 'oidc.client_id' => 'platform', 'oidc.client_secret' => 'secret']);
        $this->artisan('security:check')->expectsOutputToContain('1 point(s) à corriger')->assertFailed();

        User::factory()->superAdmin()->create(['break_glass_at' => now()]);
        $this->artisan('security:check')->expectsOutputToContain('Configuration prête pour la production.')->assertSuccessful();
    }
}
