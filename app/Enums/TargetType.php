<?php

namespace App\Enums;

/**
 * Types de ressources monitorables (cahier des charges §5). Le MVP ne gère que API.
 */
enum TargetType: string
{
    case Api = 'api';
    case Website = 'website';
    case Server = 'server';
    case Container = 'container';
    case Database = 'database';
    case Service = 'service';
    case Network = 'network';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Api => 'API',
            self::Custom => 'Custom metric',
            default => ucfirst($this->value),
        };
    }

    /** Icône Bootstrap Icons du type. */
    public function icon(): string
    {
        return match ($this) {
            self::Api => 'bi-braces',
            self::Website => 'bi-globe2',
            self::Server => 'bi-hdd-rack',
            self::Container => 'bi-box-seam',
            self::Database => 'bi-database',
            self::Service => 'bi-gear',
            self::Network => 'bi-diagram-3',
            self::Custom => 'bi-sliders',
        };
    }
}
