<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Appel à l'API d'administration de Grafana impossible ou refusé (SCRUM-48). Message technique, pour les logs :
 * l'utilisateur ne voit que « Advanced metrics are temporarily unavailable. ».
 */
class GrafanaException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
