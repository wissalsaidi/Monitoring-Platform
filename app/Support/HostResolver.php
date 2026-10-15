<?php

namespace App\Support;

/**
 * Résolution DNS d'un nom d'hôte en adresses IP (IPv4 et IPv6).
 * Classe à part pour pouvoir la simuler dans les tests (pas de réseau dans la CI).
 */
class HostResolver
{
    /** @return list<string> adresses IP ; vide si le nom ne se résout pas */
    public function resolve(string $host): array
    {
        $ipv4 = @gethostbynamel($host) ?: [];
        $ipv6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$ipv4, ...$ipv6]));
    }
}
