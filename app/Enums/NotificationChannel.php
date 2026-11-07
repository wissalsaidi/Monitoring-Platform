<?php

namespace App\Enums;

/**
 * Canal d'une notification (SCRUM-52) : email, ou dashboard seulement (aucun message envoyé) pour les règles d'une
 * team ; webhook (SCRUM-75) pour les notifications envoyées à un webhook, qui a ses propres abonnements.
 */
enum NotificationChannel: string
{
    case Email = 'email';
    case Dashboard = 'dashboard';
    case Webhook = 'webhook';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Dashboard => 'Dashboard only',
            self::Webhook => 'Webhook',
        };
    }

    /**
     * Choix d'une règle de notification (page Notifications) : les webhooks ont leurs propres abonnements.
     *
     * @return list<self>
     */
    public static function ruleChoices(): array
    {
        return [self::Email, self::Dashboard];
    }
}
