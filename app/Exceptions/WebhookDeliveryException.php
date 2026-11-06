<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Envoi d'un webhook refusé ou en échec (SCRUM-76). Le message est gardé dans l'historique et les logs : il ne
 * contient jamais l'URL ni le secret, seulement le code HTTP et un extrait de la réponse.
 */
class WebhookDeliveryException extends RuntimeException {}
