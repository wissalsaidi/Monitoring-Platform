<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Adresse IP (v4 ou v6) ou nom d'hôte (RFC 1123) : adresse d'un agent Zabbix (serveur SCRUM-69, hôte Docker SCRUM-70).
 * Elle finit dans la configuration Zabbix : aucun autre caractère n'est accepté.
 */
class HostOrIp implements ValidationRule
{
    private const HOSTNAME = '/^(?=.{1,253}$)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || (filter_var($value, FILTER_VALIDATE_IP) === false && ! preg_match(self::HOSTNAME, $value))) {
            $fail('The :attribute must be an IP address or a host name.');
        }
    }
}
