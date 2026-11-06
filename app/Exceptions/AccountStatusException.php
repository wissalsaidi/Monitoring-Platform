<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Action refusée sur un compte (SCRUM-84) : se désactiver soi-même, désactiver le dernier Super Admin actif
 * ou le seul Admin d'une team… Le message est affiché tel quel.
 */
class AccountStatusException extends RuntimeException {}
