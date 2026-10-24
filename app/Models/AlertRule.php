<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Règle de notification d'une team (SCRUM-52, cahier des charges §24) : pour une sévérité et un événement (ouverture,
 * résolution), le canal. Une règle par team, sévérité et événement ; sans règle, rien n'est envoyé.
 */
class AlertRule extends Model
{
    /** Règles d'une nouvelle team : CRITICAL et HIGH par email (ouverture et résolution), le reste au dashboard */
    public const EMAIL_BY_DEFAULT = [AlertSeverity::Critical, AlertSeverity::High];

    protected $fillable = ['team_id', 'severity', 'event', 'channel'];

    protected function casts(): array
    {
        return [
            'severity' => AlertSeverity::class,
            'event' => NotificationEvent::class,
            'channel' => NotificationChannel::class,
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Règles par défaut, une par sévérité et par événement.
     *
     * @return list<array{severity: string, event: string, channel: string}>
     */
    public static function defaults(): array
    {
        $rules = [];

        foreach (AlertSeverity::cases() as $severity) {
            foreach (NotificationEvent::cases() as $event) {
                $rules[] = [
                    'severity' => $severity->value,
                    'event' => $event->value,
                    'channel' => in_array($severity, self::EMAIL_BY_DEFAULT, true)
                        ? NotificationChannel::Email->value
                        : NotificationChannel::Dashboard->value,
                ];
            }
        }

        return $rules;
    }
}
