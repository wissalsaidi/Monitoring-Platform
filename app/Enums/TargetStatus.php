<?php

namespace App\Enums;

/**
 * Statut affiché d'une target (KPIs du dashboard, cahier des charges §20).
 */
enum TargetStatus: string
{
    case Unknown = 'unknown';
    case Up = 'up';
    case Warning = 'warning';
    case Critical = 'critical';

    public function label(): string
    {
        return strtoupper($this->value);
    }

    /** Icône Bootstrap Icons de la pastille de statut. */
    public function icon(): string
    {
        return match ($this) {
            self::Up => 'bi-check-circle-fill',
            self::Warning => 'bi-exclamation-triangle-fill',
            self::Critical => 'bi-x-octagon-fill',
            self::Unknown => 'bi-question-circle-fill',
        };
    }
}
