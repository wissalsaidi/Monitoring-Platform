<?php

namespace App\Enums;

/**
 * Rôle d'un membre dans une team (rôles du cahier des charges §26).
 */
enum TeamRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Viewer = 'viewer';
}
