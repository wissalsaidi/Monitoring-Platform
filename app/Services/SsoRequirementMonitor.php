<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Cache;

/**
 * Trace dans l'audit chaque changement de SSO_REQUIRED (SCRUM-90), constaté au démarrage de l'application : réglage de
 * .env, donc sans auteur (« System »). Le dernier état connu vient de l'audit, gardé en cache pour ne pas relire la base
 * à chaque requête.
 */
class SsoRequirementMonitor
{
    private const CACHE_KEY = 'platform.sso_required';

    public function check(): void
    {
        $required = OidcClient::required();
        $known = Cache::rememberForever(self::CACHE_KEY, fn () => (bool) (AuditLog::where('action', 'platform.sso_required')
            ->latest('id')->first()?->details['required'] ?? false));

        if ($required === $known) {
            return;
        }

        AuditLog::record('platform.sso_required', null, null, ['required' => $required, 'via' => 'system']);
        Cache::forever(self::CACHE_KEY, $required);
    }
}
