<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\OidcClient;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Contrôle de la configuration avant une mise en production (SCRUM-61, OWASP A02 / A05).
 */
class SecurityCheck extends Command
{
    protected $signature = 'security:check';

    protected $description = 'Vérifie que la configuration est prête pour la production (debug, HTTPS, cookies, logs)';

    public function handle(): int
    {
        $stack = (array) config('logging.channels.stack.channels');

        $checks = [
            'APP_ENV=production' => config('app.env') === 'production',
            'APP_DEBUG=false' => config('app.debug') === false,
            'APP_KEY défini' => filled(config('app.key')),
            'APP_URL en https://' => Str::startsWith((string) config('app.url'), 'https://'),
            'SESSION_SECURE_COOKIE=true' => config('session.secure') === true,
            'Cookies de session HttpOnly' => config('session.http_only') === true,
            'HSTS activé (SECURITY_HSTS_MAX_AGE > 0)' => (int) config('security.hsts_max_age') > 0,
            'Log principal en rotation quotidienne (LOG_STACK=daily)' => config('logging.default') === 'stack'
                && $stack === ['daily'],
            'LOG_LEVEL différent de debug' => config('logging.channels.daily.level') !== 'debug',
            'Emails envoyés pour de vrai (pas Mailpit, log ou array)' => ! in_array(config('mail.default'), ['log', 'array'], true)
                && config('mail.mailers.smtp.host') !== 'mailpit',
        ];

        // SSO obligatoire (SCRUM-90) : fournisseur configuré, et un compte de secours pour entrer s'il tombe en panne
        if (config('oidc.required')) {
            $checks['SSO_REQUIRED=true avec un fournisseur configuré (OIDC_*)'] = OidcClient::enabled();
            $checks['SSO_REQUIRED=true avec un compte de secours (platform:break-glass)'] = User::superAdmins()->active()
                ->whereNotNull('break_glass_at')->whereNotNull('password')->exists();
        }

        foreach ($checks as $label => $ok) {
            $this->components->twoColumnDetail($label, $ok ? '<fg=green>OK</>' : '<fg=red>À corriger</>');
        }

        $failed = count(array_filter($checks, fn (bool $ok) => ! $ok));
        if ($failed > 0) {
            $this->components->error("{$failed} point(s) à corriger avant la production : voir docs/exploitation/production.md");

            return self::FAILURE;
        }

        $this->components->info('Configuration prête pour la production.');

        return self::SUCCESS;
    }
}
