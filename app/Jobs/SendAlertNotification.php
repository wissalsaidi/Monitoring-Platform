<?php

namespace App\Jobs;

use App\Enums\NotificationStatus;
use App\Mail\AlertOpenedMail;
use App\Models\AlertNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envoie une notification d'alerte (SCRUM-53), par la queue Redis : la synchro Zabbix n'attend jamais le serveur mail.
 * Échec SMTP : nouvelles tentatives (après 30 s puis 2 min), puis notification en échec et journalisée. L'utilisateur
 * ne voit aucune erreur ; l'historique des notifications (SCRUM-56) montrera l'échec.
 */
class SendAlertNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> secondes avant la 2e puis la 3e tentative */
    public array $backoff = [30, 120];

    public function __construct(public readonly int $notificationId) {}

    public function handle(): void
    {
        $notification = AlertNotification::with('alert.target.team')->find($this->notificationId);

        // Supprimée entre-temps (alerte ou target supprimée), ou déjà envoyée : rien à faire
        if ($notification === null || $notification->status !== NotificationStatus::Pending) {
            return;
        }

        $notification->increment('attempts');

        try {
            Mail::to($notification->recipient)->send(new AlertOpenedMail($notification->alert));
        } catch (Throwable $e) {
            $notification->update(['error' => Str::limit($e->getMessage(), 490)]);

            throw $e;      // la queue refera une tentative
        }

        $notification->update(['status' => NotificationStatus::Sent, 'sent_at' => now(), 'error' => null]);
    }

    /** Toutes les tentatives ont échoué */
    public function failed(?Throwable $e): void
    {
        AlertNotification::whereKey($this->notificationId)->update([
            'status' => NotificationStatus::Failed,
            'error' => Str::limit((string) $e?->getMessage(), 490),
        ]);

        // Pas d'adresse email dans les logs : l'id suffit pour retrouver la notification
        Log::warning('Alert notification failed', ['notification_id' => $this->notificationId, 'error' => $e?->getMessage()]);
    }
}
