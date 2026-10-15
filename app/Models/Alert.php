<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\AlertStatus;
use Carbon\CarbonInterface;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasFactory;

    protected $fillable = [
        'monitoring_target_id', 'zabbix_event_id', 'zabbix_trigger_id', 'severity', 'status', 'message',
        'metric', 'value', 'threshold', 'started_at', 'acknowledged_at', 'resolved_at',
    ];

    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'severity' => AlertSeverity::class,
            'status' => AlertStatus::class,
            'value' => 'float',
            'threshold' => 'integer',
            'started_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(MonitoringTarget::class, 'monitoring_target_id');
    }

    /**
     * Durée de l'incident : jusqu'à la résolution, ou jusqu'à maintenant s'il est en cours.
     * Ex. « 12m 4s », « 1h 5m ».
     */
    public function durationForHumans(): string
    {
        return $this->started_at->diffForHumans($this->resolved_at ?? now(), CarbonInterface::DIFF_ABSOLUTE, true, 2);
    }

    /** Libellé de la métrique surveillée (tag "metric" du trigger). */
    public function metricLabel(): string
    {
        return match ($this->metric) {
            'response_time' => 'Response time',
            'availability' => 'Availability',
            null => 'Zabbix problem',
            default => ucfirst(str_replace('_', ' ', $this->metric)),
        };
    }

    /** Alertes non résolues (OPEN ou ACKNOWLEDGED). */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('status', AlertStatus::active());
    }
}
