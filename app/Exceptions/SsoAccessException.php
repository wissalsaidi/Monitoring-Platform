<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Connexion SSO réussie chez le fournisseur, mais refusée par la plateforme (SCRUM-89) : pas de compte, email non
 * attesté, compte lié à une autre identité. $message : raison pour les logs ; $userMessage : texte affiché.
 * $noAccessEmail : personne inconnue, à envoyer sur la page « Waiting for access » (aucun compte créé).
 */
class SsoAccessException extends RuntimeException
{
    public function __construct(string $reason, public readonly string $userMessage, public readonly ?string $noAccessEmail = null)
    {
        parent::__construct($reason);
    }
}
