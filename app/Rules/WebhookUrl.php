<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * URL d'un webhook (SCRUM-75) : la plateforme l'appelle depuis le réseau interne, donc protection SSRF de PublicUrl
 * (adresses résolues publiques), et HTTPS obligatoire. Exception : les hôtes de démo de
 * config('monitoring.webhook_allowed_hosts'), qui peuvent être internes et en HTTP.
 */
class WebhookUrl implements ValidationRule
{
    public const HTTPS = 'A webhook URL must use HTTPS.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $url = (string) $value;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $fail('The webhook URL is not valid.');

            return;
        }

        $allowed = array_map('strtolower', config('monitoring.webhook_allowed_hosts', []));
        if (in_array($host, $allowed, true)) {
            return;
        }

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            $fail(self::HTTPS);

            return;
        }

        (new PublicUrl(allowedHosts: []))->validate($attribute, $value, $fail);
    }
}
