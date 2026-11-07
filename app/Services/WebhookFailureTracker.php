<?php

namespace App\Services;

use App\Enums\TeamRole;
use App\Mail\WebhookDisabledMail;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Webhook;
use Illuminate\Support\Facades\Mail;

/**
 * Échecs consécutifs d'un webhook (SCRUM-79). Une notification compte comme un échec une fois tous ses essais épuisés.
 * Au 10e échec d'affilée, le webhook est désactivé : on n'insiste pas sur une URL morte. Les Admin de la team reçoivent
 * un email, et l'audit garde `webhook.auto_disable`. Un envoi réussi remet le compteur à zéro ; la réactivation se fait
 * à la main depuis l'onglet Webhooks.
 */
class WebhookFailureTracker
{
    public const AUTO_DISABLE_AFTER = 10;

    public function recordSuccess(Webhook $webhook): void
    {
        if ($webhook->consecutive_failures !== 0) {
            $webhook->forceFill(['consecutive_failures' => 0])->save();
        }
    }

    public function recordFailure(int $webhookId, ?string $lastError = null): void
    {
        Webhook::whereKey($webhookId)->increment('consecutive_failures');

        // Mise à jour conditionnelle : deux jobs qui échouent en même temps ne désactivent (et ne préviennent) qu'une fois
        $disabled = Webhook::whereKey($webhookId)
            ->where('active', true)
            ->where('consecutive_failures', '>=', self::AUTO_DISABLE_AFTER)
            ->update(['active' => false]);

        if ($disabled === 0) {
            return;
        }

        $webhook = Webhook::with('team')->findOrFail($webhookId);
        AuditLog::record('webhook.auto_disable', null, $webhook, [
            'name' => $webhook->name,
            'failures' => $webhook->consecutive_failures,
            'via' => 'system',
        ]);

        $webhook->team->members()
            ->wherePivot('role', TeamRole::Admin->value)
            ->whereNotNull('email_verified_at')
            ->active()
            ->each(fn (User $admin) => Mail::to($admin)->queue(new WebhookDisabledMail($webhook, $lastError)));
    }
}
