<?php

namespace App\Support;

use App\Enums\TargetType;
use App\Models\MonitoringTarget;
use App\Monitoring\Provisioners\WebsiteProvisioner;

/**
 * Colonnes des listes de targets selon le type (SCRUM-72, cahier des charges §21) : liste filtrée sur un type → ses
 * colonnes ; toutes les targets → une colonne « Key metric » (la métrique principale de chaque type).
 * Valeurs lues dans la base (last_response_ms, last_metrics) : aucun appel Zabbix.
 */
class TargetColumns
{
    /** Colonnes par type : clé de cellule → libellé */
    private const COLUMNS = [
        'api' => ['response' => 'Response time', 'http_code' => 'HTTP code'],
        'website' => ['response' => 'Response time', 'http_code' => 'HTTP code', 'certificate' => 'SSL certificate'],
        'server' => ['cpu' => 'CPU', 'memory' => 'Memory', 'disk' => 'Disk /'],
        'container' => ['state' => 'State', 'cpu' => 'CPU', 'container_memory' => 'Memory', 'restarts' => 'Restarts'],
        'database' => ['response' => 'Response time', 'connections' => 'Connections', 'queries' => 'Queries'],
    ];

    /** Métrique principale de chaque type, pour la colonne « Key metric » */
    private const KEY_METRIC = [
        'api' => 'response', 'website' => 'response', 'server' => 'cpu', 'container' => 'state', 'database' => 'connections',
    ];

    /**
     * @return array<string, string> clé de cellule → libellé ; ['key_metric' => 'Key metric'] sans filtre de type
     */
    public static function for(?TargetType $type): array
    {
        return $type !== null && isset(self::COLUMNS[$type->value])
            ? self::COLUMNS[$type->value]
            : ['key_metric' => 'Key metric'];
    }

    /** @return array{text: string, class: string} texte de la cellule et sa couleur */
    public static function cell(MonitoringTarget $target, string $column): array
    {
        if ($column === 'key_metric') {
            $key = self::KEY_METRIC[$target->type->value] ?? null;
            $cell = $key !== null ? self::cell($target, $key) : self::none();

            // Le libellé dit ce que l'on voit, sauf pour le temps de réponse (unité explicite)
            return $key !== null && $key !== 'response' && $cell['text'] !== '—'
                ? ['text' => self::COLUMNS[$target->type->value][$key].' '.$cell['text'], 'class' => $cell['class']]
                : $cell;
        }

        return match ($column) {
            'response' => self::response($target),
            'http_code' => self::httpCode($target),
            'certificate' => self::certificate($target),
            'cpu', 'memory', 'disk' => self::percent($target, $column),
            'state' => self::state($target),
            'container_memory' => self::containerMemory($target),
            'restarts' => self::number($target->lastMetric('restarts')),
            'connections' => self::connections($target),
            'queries' => self::queries($target),
            default => self::none(),
        };
    }

    private static function response(MonitoringTarget $target): array
    {
        $ms = $target->last_response_ms;

        if ($ms === null) {
            return self::none();
        }

        $class = match (true) {
            $target->critical_threshold_ms === null => '',
            $ms > $target->critical_threshold_ms => 'text-danger',
            $ms > $target->warning_threshold_ms => 'text-warning',
            default => '',
        };

        return ['text' => number_format($ms).' ms', 'class' => $class];
    }

    private static function httpCode(MonitoringTarget $target): array
    {
        $code = $target->lastMetric('http_code');

        return $code ? ['text' => (string) $code, 'class' => $code >= 200 && $code < 300 ? '' : 'text-danger'] : self::none();
    }

    private static function certificate(MonitoringTarget $target): array
    {
        $days = $target->lastMetric('certificate_days');

        if (! WebsiteProvisioner::checksCertificate($target)) {
            return ['text' => 'not checked', 'class' => 'text-body-secondary'];
        }

        return match (true) {
            ! is_numeric($days) => self::none(),
            $days < 0 => ['text' => 'expired', 'class' => 'text-danger'],
            default => [
                'text' => number_format($days).' '.str('day')->plural($days).' left',
                'class' => match (true) {
                    $days < WebsiteProvisioner::CERTIFICATE_CRITICAL_DAYS => 'text-danger',
                    $days < WebsiteProvisioner::CERTIFICATE_WARNING_DAYS => 'text-warning',
                    default => '',
                },
            ],
        };
    }

    /** CPU / mémoire / disque : la couleur suit les seuils du serveur, ou la valeur seule pour un conteneur */
    private static function percent(MonitoringTarget $target, string $resource): array
    {
        $value = $target->lastMetric($resource);

        if (! is_numeric($value)) {
            return self::none();
        }

        $warning = $target->setting("{$resource}_warning");
        $critical = $target->setting("{$resource}_critical");
        $class = match (true) {
            $critical !== null && $value > $critical => 'text-danger',
            $warning !== null && $value > $warning => 'text-warning',
            default => '',
        };

        return ['text' => number_format($value, 1).' %', 'class' => $class];
    }

    private static function state(MonitoringTarget $target): array
    {
        $state = $target->lastMetric('state');

        return is_string($state) && $state !== ''
            ? ['text' => $state, 'class' => $state === 'running' ? 'text-success' : 'text-danger']
            : self::none();
    }

    private static function containerMemory(MonitoringTarget $target): array
    {
        $bytes = $target->lastMetric('memory');

        if (! is_numeric($bytes)) {
            return self::none();
        }

        $mb = $bytes / 1048576;
        $warning = $target->setting('memory_warning');

        return ['text' => number_format($mb, 1).' MB', 'class' => $warning !== null && $mb > $warning ? 'text-warning' : ''];
    }

    private static function connections(MonitoringTarget $target): array
    {
        $value = $target->lastMetric('connections');
        $warning = $target->setting('connections_warning');

        return is_numeric($value)
            ? ['text' => number_format($value), 'class' => $warning !== null && $value > $warning ? 'text-warning' : '']
            : self::none();
    }

    private static function queries(MonitoringTarget $target): array
    {
        $value = $target->lastMetric('queries_per_sec');

        return is_numeric($value) ? ['text' => number_format($value, 1).' / s', 'class' => ''] : self::none();
    }

    private static function number(int|float|string|null $value): array
    {
        return is_numeric($value) ? ['text' => number_format($value), 'class' => ''] : self::none();
    }

    private static function none(): array
    {
        return ['text' => '—', 'class' => 'text-body-secondary'];
    }
}
