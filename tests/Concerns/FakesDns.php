<?php

namespace Tests\Concerns;

use App\Support\HostResolver;

/**
 * Résolution DNS simulée : aucun test ne dépend du réseau.
 * Par défaut, tout nom se résout en une adresse publique (93.184.216.34).
 */
trait FakesDns
{
    /** @var array<string, list<string>> nom d'hôte => adresses IP simulées */
    protected array $dnsRecords = [];

    protected function fakeDns(): void
    {
        $this->app->instance(HostResolver::class, new class($this) extends HostResolver
        {
            public function __construct(private readonly object $test) {}

            public function resolve(string $host): array
            {
                return $this->test->resolveFakeDns($host);
            }
        });
    }

    /** @return list<string> */
    public function resolveFakeDns(string $host): array
    {
        return $this->dnsRecords[$host] ?? ['93.184.216.34'];
    }
}
