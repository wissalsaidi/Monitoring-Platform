<?php

namespace App\Enums;

/**
 * Rôle d'un membre dans une team (rôles du cahier des charges §26).
 * Les rôles s'emboîtent : Viewer ⊂ Manager ⊂ Admin.
 */
enum TeamRole: string
{
    case Admin = 'admin';
    case Manager = 'manager';
    case Viewer = 'viewer';

    /**
     * Matrice rôle → permissions (SCRUM-58). Les policies et l'interface ne testent
     * jamais le rôle directement : elles demandent une permission.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        $viewer = [
            Permission::DashboardView,
            Permission::TargetView,
            Permission::AlertView,
        ];

        $manager = [
            ...$viewer,
            Permission::TargetCreate,
            Permission::TargetUpdate,
            Permission::TargetDelete,
            Permission::AlertAcknowledge,
            Permission::NotificationManage,
        ];

        return match ($this) {
            self::Viewer => $viewer,
            self::Manager => $manager,
            self::Admin => Permission::cases(),
        };
    }

    /** @return list<self> rôles qui ont cette permission */
    public static function withPermission(Permission $permission): array
    {
        return array_values(array_filter(self::cases(), fn (self $role) => $role->can($permission)));
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
