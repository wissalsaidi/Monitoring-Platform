<?php

namespace App\Enums;

/**
 * Canal d'une règle de notification (SCRUM-52) : email, ou dashboard seulement (aucun message envoyé).
 */
enum NotificationChannel: string
{
    case Email = 'email';
    case Dashboard = 'dashboard';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Dashboard => 'Dashboard only',
        };
    }
}
