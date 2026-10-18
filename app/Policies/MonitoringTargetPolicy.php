<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;

/**
 * Accès aux targets : permission demandée dans la team de la target (RBAC, SCRUM-58).
 * Un utilisateur sans rôle dans la team n'a aucune permission : il ne voit pas la target.
 */
class MonitoringTargetPolicy
{
    public function view(User $user, MonitoringTarget $target): bool
    {
        return $user->hasPermission(Permission::TargetView, $target->team);
    }

    public function create(User $user, Team $team): bool
    {
        return $user->hasPermission(Permission::TargetCreate, $team);
    }

    public function update(User $user, MonitoringTarget $target): bool
    {
        return $user->hasPermission(Permission::TargetUpdate, $target->team);
    }

    public function delete(User $user, MonitoringTarget $target): bool
    {
        return $user->hasPermission(Permission::TargetDelete, $target->team);
    }
}
