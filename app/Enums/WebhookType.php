<?php

namespace App\Enums;

/**
 * Type d'un webhook (SCRUM-74) : format du message envoyé. Générique = JSON signé (HMAC-SHA256) pour un outil
 * interne (ITSM, astreinte) ; Slack et Microsoft Teams = message lisible dans un canal (SCRUM-77).
 */
enum WebhookType: string
{
    case Generic = 'generic';
    case Slack = 'slack';
    case Teams = 'teams';

    public function label(): string
    {
        return match ($this) {
            self::Generic => 'Generic (signed JSON)',
            self::Slack => 'Slack',
            self::Teams => 'Microsoft Teams',
        };
    }
}
