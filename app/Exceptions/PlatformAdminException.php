<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Action refusée sur les Super Admin de la plateforme (SCRUM-81) : par exemple retirer le dernier.
 * Le message est destiné à l'utilisateur.
 */
class PlatformAdminException extends RuntimeException {}
