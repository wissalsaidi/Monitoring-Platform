<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Configuration de production sécurisée : en-têtes HTTP, logs (SCRUM-61, OWASP A05).
 */
class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /** @return array<string, string> directive => sources */
    private function csp(string $header): array
    {
        return collect(explode(';', $header))
            ->mapWithKeys(fn (string $directive) => [strtok(trim($directive), ' ') => trim(strstr(trim($directive), ' '))])
            ->all();
    }

    public function test_pages_send_the_security_headers(): void
    {
        $response = $this->get('/login')->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy');

        $csp = $this->csp($response->headers->get('Content-Security-Policy'));
        $this->assertSame("'self'", $csp['default-src']);
        $this->assertSame("'self'", $csp['script-src']);
        $this->assertSame("'none'", $csp['frame-ancestors']);
        $this->assertSame("'none'", $csp['object-src']);
        $this->assertSame("'self'", $csp['form-action']);
    }

    /** Déconnexion SSO (SCRUM-90) : le formulaire « Log out » peut être redirigé vers le fournisseur, et seulement lui */
    public function test_form_action_allows_the_sso_provider(): void
    {
        config(['oidc.issuer' => 'https://sso.company.com:8443/realms/acme', 'oidc.client_id' => 'platform', 'oidc.client_secret' => 'secret']);

        $csp = $this->csp($this->get('/login')->headers->get('Content-Security-Policy'));

        $this->assertSame("'self' https://sso.company.com:8443", $csp['form-action']);
    }

    public function test_authenticated_pages_send_them_too(): void
    {
        $this->actingAs(User::factory()->create())->get(route('account.edit'))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Content-Security-Policy');
    }

    public function test_hsts_is_sent_over_https_only(): void
    {
        $this->get('http://monitoring.example.com/login')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://monitoring.example.com/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_hsts_is_never_sent_for_localhost(): void
    {
        // Le navigateur l'appliquerait à localhost:8080 (Zabbix), localhost:3000 (Grafana)…
        $this->get('https://localhost/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_can_be_disabled(): void
    {
        config(['security.hsts_max_age' => 0]);

        $this->get('https://monitoring.example.com/login')->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_the_vite_dev_server_is_allowed_when_npm_run_dev_is_running(): void
    {
        $hot = storage_path('framework/testing/hot');
        File::ensureDirectoryExists(dirname($hot));
        File::put($hot, 'http://localhost:5173');
        $this->withVite();
        Vite::useHotFile($hot);

        try {
            $csp = $this->csp($this->get('/login')->headers->get('Content-Security-Policy'));
        } finally {
            File::delete($hot);
        }

        $this->assertSame("'self' http://localhost:5173", $csp['script-src']);
        $this->assertStringContainsString('ws://localhost:5173', $csp['connect-src']);
    }

    public function test_the_debug_error_page_keeps_working_without_csp(): void
    {
        Route::middleware('web')->get('/_boom', fn () => throw new RuntimeException('boom'));

        config(['app.debug' => true]);
        $this->get('/_boom')->assertStatus(500)->assertHeaderMissing('Content-Security-Policy')->assertHeader('X-Frame-Options', 'DENY');

        config(['app.debug' => false]);
        $this->get('/_boom')->assertStatus(500)->assertHeader('Content-Security-Policy');
    }

    public function test_views_contain_no_inline_javascript(): void
    {
        // La CSP bloquerait onchange="…", onsubmit="…" et <script> sans src : utiliser data-auto-submit / data-confirm
        $offenders = [];
        foreach (Finder::create()->files()->in(resource_path('views'))->name('*.blade.php') as $file) {
            if (preg_match('/\son[a-z]+\s*=|<script(?![^>]*\ssrc=)/i', $file->getContents())) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_the_main_log_rotates_daily_for_14_days(): void
    {
        $config = require config_path('logging.php');

        $this->assertSame(['daily'], $config['channels']['stack']['channels']);
        $this->assertSame('daily', $config['channels']['daily']['driver']);
        $this->assertEquals(14, $config['channels']['daily']['days']);
    }
}
