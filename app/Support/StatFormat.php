<?php

namespace App\Support;

use Carbon\CarbonInterval;

/**
 * Affichage des statistiques (SCRUM-51) : onglet History et dashboard. null → null (la vue affiche « — »).
 */
final class StatFormat
{
    /** 100 → « 100 », 99.874 → « 99.87 » */
    public static function percent(?float $value): ?string
    {
        return $value === null ? null : number_format($value, $value >= 99.995 || $value == floor($value) ? 0 : 2);
    }

    /** Couleur d'une disponibilité : ≥ 99,9 % vert, ≥ 99 % orange, sinon rouge */
    public static function availabilityColor(?float $value): string
    {
        return match (true) {
            $value === null => 'text-body-secondary',
            $value >= 99.9 => 'text-success',
            $value >= 99 => 'text-warning',
            default => 'text-danger',
        };
    }

    /** 3624 → « 1h 24s » ; 95 → « 1m 35s » */
    public static function duration(?int $seconds): ?string
    {
        return $seconds === null ? null : CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }
}
