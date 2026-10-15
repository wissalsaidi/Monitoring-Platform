<?php

namespace App\Exceptions\Zabbix;

/**
 * Zabbix injoignable : réseau, timeout ou réponse HTTP en erreur.
 */
class ZabbixConnectionException extends ZabbixException
{
    public function __construct(
        string $message,
        ?string $method = null,
        public readonly bool $timedOut = false,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $method, $previous);
    }

    public function userMessage(): string
    {
        return $this->timedOut
            ? 'Monitoring request timed out.'
            : 'Monitoring service temporarily unavailable.';
    }
}
