<?php

namespace App\Policies;

use App\Enums\TeamRole;
use App\Models\MonitoringTarget;
use App\Models\Team;
use App\Models\User;

/**
 * MVP : un membre d'une team voit les targets de sa team (SCRUM-31),
 * seuls les Admin et Manager de la team en créent (SCRUM-33, rôles du §26).
 * Les permissions fines (target.create, target.delete…) viendront avec le RBAC (V1).
 */
class MonitoringTargetPolicy
{
    public function view(User $user, MonitoringTarget $target): bool
    {
        return $user->belongsToTeam($target->team);
    }

    public function create(User $user, Team $team): bool
    {
        return in_array($user->roleIn($team), [TeamRole::Admin, TeamRole::Manager], true);
    }

    /** SCRUM-35 : modifier ou supprimer = Admin ou Manager de la team de la target */
    public function update(User $user, MonitoringTarget $target): bool
    {
        return $this->create($user, $target->team);
    }

    public function delete(User $user, MonitoringTarget $target): bool
    {
        return $this->create($user, $target->team);
    }
}
