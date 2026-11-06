<?php

namespace App\Enums;

/**
 * Permissions de plateforme (SCRUM-81) : au-dessus des teams, sans team en paramètre.
 * Qui a quoi : PlatformRole::permissions(). Elles ne donnent AUCUN accès aux données d'une team (Permission, TeamRole).
 */
enum PlatformPermission: string
{
    case UsersManage = 'platform.users.manage';
    case TeamsManage = 'platform.teams.manage';
    case AuditView = 'platform.audit.view';
}
