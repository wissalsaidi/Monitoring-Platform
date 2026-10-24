<?php

namespace App\Enums;

/**
 * Moment d'une alerte qui peut déclencher une notification (SCRUM-52, cahier des charges §24).
 */
enum NotificationEvent: string
{
    case Opened = 'opened';
    case Resolved = 'resolved';

    public function label(): string
    {
        return match ($this) {
            self::Opened => 'Opened',
            self::Resolved => 'Resolved',
        };
    }
}
