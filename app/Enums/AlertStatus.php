<?php

namespace App\Enums;

/**
 * Cycle de vie d'une alerte (cahier des charges §13).
 */
enum AlertStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';

    /** Statuts d'une alerte encore en cours (non résolue). */
    public static function active(): array
    {
        return [self::Open, self::Acknowledged];
    }

    public function label(): string
    {
        return strtoupper($this->value);
    }
}
