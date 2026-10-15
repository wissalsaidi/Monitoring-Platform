<?php

namespace App\Exceptions\Zabbix;

/**
 * Zabbix a répondu, mais avec une erreur JSON-RPC (paramètre invalide, objet inexistant…).
 */
class ZabbixApiException extends ZabbixException
{
    public function __construct(
        string $message,
        ?string $method = null,
        public readonly int $zabbixCode = 0,
        public readonly ?string $zabbixData = null,
    ) {
        parent::__construct($message, $method);
    }
}
