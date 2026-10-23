<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\TargetStatus;
use App\Enums\TargetType;
use App\Enums\TeamRole;
use App\Monitoring\ProvisionerRegistry;
use App\Monitoring\TargetProvisioner;
use Database\Factories\MonitoringTargetFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'url', 'check_interval', 'warning_threshold_ms', 'critical_threshold_ms', 'settings', 'zabbix_host_id',
        'last_checked_at', 'last_response_ms', 'last_metrics',
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
            'settings' => 'array',
            'last_checked_at' => 'datetime',
            'last_response_ms' => 'float',
            'last_metrics' => 'array',
        ];
    }

    /**
     * Valeur de la configuration propre au type (colonne JSON settings, SCRUM-66).
     * Ex. : $target->setting('port', 10050)
     */
    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Dernière valeur d'une métrique propre au type, recopiée par la synchro (SCRUM-68) ; null si pas encore mesurée.
     * Ex. : $target->lastMetric('certificate_days'), $target->lastMetric('os') (texte)
     */
    public function lastMetric(string $name): int|float|string|null
    {
        return $this->last_metrics[$name] ?? null;
    }

    /**
     * Ce que surveille la target, pour les listes : l'URL, adresse:port de l'agent d'un serveur (SCRUM-69),
     * ou « conteneur @ hôte:port » (SCRUM-70).
     */
    public function displayAddress(): ?string
    {
        if ($this->url !== null) {
            return $this->url;
        }

        $address = $this->setting('address');
        $agent = $address !== null ? $address.($this->setting('port') ? ':'.$this->setting('port') : '') : null;
        $container = $this->setting('container');

        return $container !== null ? $container.($agent !== null ? ' @ '.$agent : '') : $agent;
    }

    /** Provisioner Zabbix du type de la target. */
    public function provisioner(): TargetProvisioner
    {
        return app(ProvisionerRegistry::class)->for($this->type);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /**
     * Targets des teams dont l'utilisateur est membre (SCRUM-31).
     * Usage : MonitoringTarget::visibleTo($user)->get()
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        // Teams où le rôle de l'utilisateur donne target.view (aujourd'hui : tous les rôles, SCRUM-58)
        $roles = array_map(fn (TeamRole $role) => $role->value, TeamRole::withPermission(Permission::TargetView));

        $query->whereIn('team_id', $user->teams()->wherePivotIn('role', $roles)->select('teams.id'));
    }

    /**
     * Données du dernier check trop anciennes : plus de 3 intervalles de check (+ 2 min pour la synchro)
     * sans nouvelle mesure. Signe d'un Zabbix arrêté ou d'un check bloqué.
     */
    public function isStale(): bool
    {
        return $this->last_checked_at !== null
            && $this->last_checked_at->lt(now()->subSeconds(3 * $this->check_interval + 120));
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
