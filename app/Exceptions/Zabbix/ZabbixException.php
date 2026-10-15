<?php

namespace App\Exceptions\Zabbix;

use RuntimeException;

/**
 * Erreur liée à Zabbix. getMessage() contient le détail technique (pour les logs),
 * userMessage() le texte affichable à l'utilisateur (cahier des charges §47).
 */
class ZabbixException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $method = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function userMessage(): string
    {
        return 'Monitoring service temporarily unavailable.';
    }
}
