<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    protected $fillable = ['name', 'zabbix_hostgroup_id'];

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
