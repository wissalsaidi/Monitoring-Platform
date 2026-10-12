<?php

namespace App\Enums;

/**
 * Sévérité d'une alerte (cahier des charges §13) et correspondance avec les sévérités Zabbix (SCRUM-39).
 *
 * | Zabbix              | Plateforme | Trigger créé par MonitoringService        |
 * |---------------------|------------|-------------------------------------------|
 * | 0 Not classified    | INFO       |                                           |
 * | 1 Information       | INFO       |                                           |
 * | 2 Warning           | WARNING    | temps de réponse > seuil warning          |
 * | 3 Average           | WARNING    |                                           |
 * | 4 High              | HIGH       | temps de réponse > seuil critical         |
 * | 5 Disaster          | CRITICAL   | target indisponible                       |
 */
enum AlertSeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case High = 'high';
    case Critical = 'critical';

    public static function fromZabbix(int|string $severity): self
    {
        return match ((int) $severity) {
            0, 1 => self::Info,
            2, 3 => self::Warning,
            4 => self::High,
            5 => self::Critical,
            default => throw new \ValueError("Unknown Zabbix severity: $severity"),
        };
    }

    /** Statut que prend une target tant qu'une alerte de cette sévérité est active. */
    public function targetStatus(): TargetStatus
    {
        return match ($this) {
            self::Info => TargetStatus::Up,
            self::Warning => TargetStatus::Warning,
            self::High, self::Critical => TargetStatus::Critical,
        };
    }

    /** Ordre de gravité, pour retenir la pire alerte d'une target. */
    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Warning => 1,
            self::High => 2,
            self::Critical => 3,
        };
    }

    public function label(): string
    {
        return strtoupper($this->value);
    }
}
