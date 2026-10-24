<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    public const DEFAULT_NAME = 'Default';

    /** grafana_org_id, zabbix_usrgrp_id, zabbix_user_id : accès à Grafana de la team (SCRUM-48, GrafanaAccessService) */
    protected $fillable = ['name', 'zabbix_hostgroup_id', 'grafana_org_id', 'zabbix_usrgrp_id', 'zabbix_user_id'];

    protected function casts(): array
    {
        return ['grafana_org_id' => 'integer'];
    }

    /** Toute nouvelle team reçoit les règles de notification par défaut (SCRUM-52) */
    protected static function booted(): void
    {
        static::created(fn (Team $team) => $team->alertRules()->createMany(AlertRule::defaults()));
    }

    /** Règles de notification (SCRUM-52) */
    public function alertRules(): HasMany
    {
        return $this->hasMany(AlertRule::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_members')
            ->using(TeamMember::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /** Invitations en attente (SCRUM-59) */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function monitoringTargets(): HasMany
    {
        return $this->hasMany(MonitoringTarget::class);
    }

    /** Nom du host group Zabbix de la team (unique : l'id est inclus). */
    public function zabbixHostGroupName(): string
    {
        return "Monitoring Platform/{$this->name} #{$this->id}";
    }
}
