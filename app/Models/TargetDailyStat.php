<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Statistiques d'une target pour une journée (SCRUM-51), calculées par StatisticsService.
 */
class TargetDailyStat extends Model
{
    protected $fillable = [
        'monitoring_target_id', 'date', 'covered_seconds', 'checks', 'availability', 'downtime_seconds',
        'avg_response_ms', 'response_samples', 'incidents', 'resolved_incidents', 'mttr_seconds', 'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
            'availability' => 'float',
            'avg_response_ms' => 'float',
            'computed_at' => 'datetime',
        ];
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(MonitoringTarget::class, 'monitoring_target_id');
    }
}
