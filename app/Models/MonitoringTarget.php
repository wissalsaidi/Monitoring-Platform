<?php

namespace App\Models;

use App\Enums\TargetStatus;
use App\Enums\TargetType;
use Database\Factories\MonitoringTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringTarget extends Model
{
    /** @use HasFactory<MonitoringTargetFactory> */
    use HasFactory;

    /** Intervalles de check proposés dans le formulaire (secondes => libellé). */
    public const CHECK_INTERVALS = [
        30 => '30 seconds',
        60 => '1 minute',
        120 => '2 minutes',
        300 => '5 minutes',
        600 => '10 minutes',
        900 => '15 minutes',
        1800 => '30 minutes',
        3600 => '1 hour',
    ];

    /** Seuil maximal : au-delà, le timeout du web scenario Zabbix (15 s) se déclenche avant. */
    public const MAX_THRESHOLD_MS = 10000;

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

    /**
     * Targets des teams dont l'utilisateur est membre (SCRUM-31).
     * Usage : MonitoringTarget::visibleTo($user)->get()
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $query->whereIn('team_id', $user->teams()->select('teams.id'));
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
