<?php

namespace App\Enums;

/**
 * Rôle d'un utilisateur sur toute la plateforme (SCRUM-81), distinct de son rôle dans chaque team (TeamRole).
 * Un utilisateur sans rôle de plateforme (null) n'a que ses rôles de team.
 */
enum PlatformRole: string
{
    case SuperAdmin = 'super_admin';

    /** @return list<PlatformPermission> */
    public function permissions(): array
    {
        return match ($this) {
            self::SuperAdmin => PlatformPermission::cases(),
        };
    }

    public function can(PlatformPermission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
        };
    }
}
