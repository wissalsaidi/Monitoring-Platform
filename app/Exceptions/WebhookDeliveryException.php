<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Envoi d'un webhook refusé ou en échec (SCRUM-76). Le message est gardé dans l'historique et les logs : il ne
 * contient jamais l'URL ni le secret, seulement le code HTTP et un extrait de la réponse.
 * Le code HTTP est aussi gardé à part (SCRUM-79) ; null si le receveur n'a pas répondu.
 */
class WebhookDeliveryException extends RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}
