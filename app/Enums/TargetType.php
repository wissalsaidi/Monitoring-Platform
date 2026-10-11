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
}
