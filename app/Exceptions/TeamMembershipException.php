<?php

namespace App\Exceptions;

use RuntimeException;

/** Action refusée sur les membres d'une team ; le message est affiché à l'utilisateur. */
class TeamMembershipException extends RuntimeException
{
    public static function lastAdmin(): self
    {
        return new self('A team must keep at least one Admin. Give the Admin role to another member first.');
    }
}
