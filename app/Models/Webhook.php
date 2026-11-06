<?php

namespace App\Models;

use App\Enums\AlertSeverity;
use App\Enums\NotificationEvent;
use App\Enums\WebhookType;
use Database\Factories\WebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Webhook d'une team (SCRUM-75) : message Slack, Microsoft Teams ou appel HTTP générique signé, à l'ouverture et à la
 * résolution des alertes des sévérités choisies. URL et secret chiffrés en base, jamais sérialisés.
 */
class Webhook extends Model
{
    /** @use HasFactory<WebhookFactory> */
    use HasFactory;

    protected $fillable = ['team_id', 'name', 'type', 'url', 'severities', 'events', 'active'];

    protected $hidden = ['url', 'secret'];

    protected $attributes = [
        'active' => true,
        'consecutive_failures' => 0,
    ];

    protected function casts(): array
    {
        return [
            'type' => WebhookType::class,
            'url' => 'encrypted',
            'secret' => 'encrypted',
            'severities' => AsEnumCollection::of(AlertSeverity::class),
            'events' => AsEnumCollection::of(NotificationEvent::class),
            'active' => 'boolean',
            'consecutive_failures' => 'integer',
        ];
    }

    /** Secret de signature (HMAC, SCRUM-76) créé avec le webhook, hors $fillable : jamais choisi par un formulaire */
    protected static function booted(): void
    {
        static::creating(function (Webhook $webhook) {
            $webhook->secret ??= Str::random(48);
        });
    }

    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('active', true);
    }

    /** Abonné à cet événement pour cette sévérité ? */
    public function subscribedTo(AlertSeverity $severity, NotificationEvent $event): bool
    {
        return $this->active && $this->severities->contains($severity) && $this->events->contains($event);
    }

    /** Adresse affichable : schéma et hôte seulement (le chemin d'un webhook Slack ou Teams vaut un mot de passe) */
    public function maskedUrl(): string
    {
        $parts = parse_url((string) $this->url);

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '?').'/…';
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(AlertNotification::class);
    }
}
