<?php

namespace App\Jobs;

use App\Enums\NotificationEvent;
use App\Enums\NotificationStatus;
use App\Mail\AlertOpenedMail;
use App\Mail\AlertResolvedMail;
use App\Models\AlertNotification;
use App\Services\IncidentHistoryService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envoie une notification d'alerte (SCRUM-53 ouverture, SCRUM-54 résolution), par la queue Redis : la synchro Zabbix n'attend jamais le serveur mail.
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
            Mail::to($notification->recipient)->send($this->mail($notification));
        } catch (Throwable $e) {
            $notification->update(['error' => Str::limit($e->getMessage(), 490)]);

            throw $e;      // la queue refera une tentative
        }

        $notification->update(['status' => NotificationStatus::Sent, 'sent_at' => now(), 'error' => null]);
    }

    /** Email de l'événement : ouverture (SCRUM-53) ou résolution, avec la valeur maximale de l'incident si connue (SCRUM-54) */
    private function mail(AlertNotification $notification): Mailable
    {
        $alert = $notification->alert;

        return match ($notification->event) {
            NotificationEvent::Opened => new AlertOpenedMail($alert),
            NotificationEvent::Resolved => new AlertResolvedMail(
                $alert,
                app(IncidentHistoryService::class)->peaks($alert->target, collect([$alert]))[$alert->id] ?? null,
            ),
        };
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
