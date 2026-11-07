<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Échec d'une connexion SSO (SCRUM-88). Le message va dans les logs seulement (jamais à l'écran) et ne contient
 * jamais de jeton, de code ni de secret.
 */
class OidcException extends RuntimeException {}
