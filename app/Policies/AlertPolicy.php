<?php

namespace App\Policies;

use App\Enums\AlertStatus;
use App\Enums\TeamRole;
use App\Models\Alert;
use App\Models\User;

/**
 * MVP : seuls les Admin et Manager de la team de la target acquittent une alerte OPEN (§26, SCRUM-42).
 */
class AlertPolicy
{
    public function acknowledge(User $user, Alert $alert): bool
    {
        return $alert->status === AlertStatus::Open
            && in_array($user->roleIn($alert->target->team), [TeamRole::Admin, TeamRole::Manager], true);
    }
}
