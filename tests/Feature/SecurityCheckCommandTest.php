<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * php artisan security:check (SCRUM-61).
 */
class SecurityCheckCommandTest extends TestCase
{
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
}
