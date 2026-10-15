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
}
