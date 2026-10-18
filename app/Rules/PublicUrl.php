<?php

namespace App\Rules;

use App\Support\HostResolver;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Protection SSRF (SCRUM-57, OWASP A10) : l'URL d'une target doit viser une adresse publique.
 * Zabbix appelle cette URL depuis le réseau interne ; sans ce contrôle, un utilisateur pourrait
 * lui faire interroger localhost, la base de données ou un autre service interne.
 *
 * Le contrôle porte sur les adresses IP réellement résolues, pas seulement sur le texte de l'URL.
 * Les hôtes de config('monitoring.allowed_internal_hosts') sont acceptés (ex. la fake API de démo).
 */
class PublicUrl implements ValidationRule
{
    public const MESSAGE = 'The URL must point to a public address. Local and internal network addresses are not allowed.';

    public const UNRESOLVED = 'The host of this URL cannot be found.';

    /** Plages refusées en plus de celles de filter_var (privées et réservées) */
    private const EXTRA_BLOCKED_RANGES = [
        '100.64.0.0/10',   // NAT des opérateurs (CGNAT)
        '0.0.0.0/8',
    ];

    public function __construct(private readonly ?HostResolver $resolver = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = strtolower(trim((string) parse_url((string) $value, PHP_URL_HOST), '[]'));

        if ($host === '') {
            return; // URL invalide : déjà signalé par la règle « url »
        }

        if (in_array($host, $this->allowedHosts(), true)) {
            return;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            $fail(self::MESSAGE);

            return;
        }

        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ($this->resolver ?? app(HostResolver::class))->resolve($host);

        if ($ips === []) {
            $fail(self::UNRESOLVED);

            return;
        }

        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                $fail(self::MESSAGE);

                return;
            }
        }
    }

    /** Adresse publique : ni privée, ni réservée (loopback, link-local, multicast…), ni CGNAT. */
    public static function isPublic(string $ip): bool
    {
        // IPv6 qui encapsule une IPv4 (::ffff:127.0.0.1) : on contrôle l'IPv4
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m)) {
            $ip = $m[1];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        foreach (self::EXTRA_BLOCKED_RANGES as $range) {
            if (self::inRange($ip, $range)) {
                return false;
            }
        }

        return true;
    }

    private static function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);

        return (ip2long($ip) & $mask) === (ip2long($subnet) & $mask);
    }

    /** @return list<string> */
    private function allowedHosts(): array
    {
        return array_map('strtolower', config('monitoring.allowed_internal_hosts', []));
    }
}
