<?php

namespace App\Enums;

/**
 * État d'une notification (SCRUM-52) : à envoyer, envoyée, ou en échec après les nouvelles tentatives.
 */
enum NotificationStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';

    public function label(): string
    {
        return strtoupper($this->value);
    }
}
