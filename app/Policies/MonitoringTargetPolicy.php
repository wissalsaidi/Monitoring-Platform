<?php

namespace App\Policies;

use App\Models\MonitoringTarget;
use App\Models\User;

/**
 * MVP : un membre d'une team voit les targets de sa team (SCRUM-31).
 * Les permissions fines par rôle (target.create, target.delete…) viendront avec le RBAC (V1).
 */
class MonitoringTargetPolicy
{
    public function view(User $user, MonitoringTarget $target): bool
    {
        return $user->belongsToTeam($target->team);
    }
}
