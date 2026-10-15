<?php

namespace App\Models;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use Database\Factories\MonitoringTargetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringTarget extends Model
{
    /** @use HasFactory<MonitoringTargetFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id', 'created_by', 'name', 'type', 'description', 'status', 'enabled',
        'url', 'check_interval', 'warning_threshold_ms', 'critical_threshold_ms', 'zabbix_host_id',
    ];

    protected $attributes = [
        'status' => 'unknown',
        'enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'type' => TargetType::class,
            'status' => TargetStatus::class,
            'enabled' => 'boolean',
            'check_interval' => 'integer',
            'warning_threshold_ms' => 'integer',
            'critical_threshold_ms' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Nom technique du host Zabbix (unique, utilisé dans les expressions de triggers). */
    public function zabbixHostName(): string
    {
        return "target-{$this->id}";
    }

    /** Nom visible du host Zabbix (Zabbix l'impose unique, d'où l'id). */
    public function zabbixVisibleName(): string
    {
        return "{$this->name} #{$this->id}";
    }
}
