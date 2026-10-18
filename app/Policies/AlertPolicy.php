<?php

namespace App\Policies;

use App\Enums\AlertStatus;
use App\Enums\Permission;
use App\Models\Alert;
use App\Models\User;

/**
 * Accès aux alertes : permission demandée dans la team de la target de l'alerte (RBAC, SCRUM-58).
 */
class AlertPolicy
{
    public function view(User $user, Alert $alert): bool
    {
        return $user->hasPermission(Permission::AlertView, $alert->target->team);
    }

    /** Seule une alerte OPEN s'acquitte (SCRUM-42) */
    public function acknowledge(User $user, Alert $alert): bool
    {
        return $alert->status === AlertStatus::Open
            && $user->hasPermission(Permission::AlertAcknowledge, $alert->target->team);
    }
}
