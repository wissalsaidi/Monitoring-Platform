<?php

namespace App\Models;

use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Notification d'une alerte à un membre de la team (SCRUM-52) ou à un webhook (SCRUM-75), table « notifications » : historique des envois et
 * garde-fou contre les doublons (une seule par alerte, destinataire, canal et événement).
 * Nom différent de la table pour ne pas confondre avec Illuminate\Notifications\Notification.
 */
class AlertNotification extends Model
{
    protected $table = 'notifications';

    protected $fillable = ['alert_id', 'user_id', 'webhook_id', 'recipient', 'channel', 'event', 'status', 'attempts', 'error', 'sent_at'];

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'event' => NotificationEvent::class,
            'status' => NotificationStatus::class,
            'sent_at' => 'datetime',
        ];
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(Alert::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Webhook destinataire (SCRUM-75) ; null pour un email, ou si le webhook a été supprimé depuis */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }
}
