<?php

namespace App\Http\Controllers;

use App\Enums\NotificationChannel;
use App\Enums\NotificationStatus;
use App\Enums\Permission;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Historique des notifications de la team (SCRUM-56) : qui a été prévenu, quand, et si l'envoi a échoué.
 * Tout membre de la team (alert.view). Adresse email des destinataires et message d'erreur SMTP : Admin seulement
 * (user.view, configuration.manage) ; les autres voient le nom du membre et « échec après N essais ».
 */
class NotificationHistoryController extends Controller
{
    public const PER_PAGE = 25;

    public function __invoke(Request $request, Team $team): View
    {
        Gate::authorize(Permission::AlertView->value, $team);

        $targets = $team->monitoringTargets()->orderBy('name')->get(['id', 'name']);
        $filters = $request->validate([
            'status' => ['nullable', Rule::enum(NotificationStatus::class)],
            // SCRUM-79 : email ou webhook, et un webhook de la team
            'channel' => ['nullable', Rule::enum(NotificationChannel::class)->only([NotificationChannel::Email, NotificationChannel::Webhook])],
            'webhook' => ['nullable', 'integer', Rule::in($team->webhooks()->pluck('id')->all())],
            // Seules les targets et alertes de la team : impossible de lire celles d'une autre team
            'target' => ['nullable', 'integer', Rule::in($targets->modelKeys())],
            'alert' => ['nullable', 'integer', Rule::exists('alerts', 'id')->whereIn('monitoring_target_id', $targets->modelKeys())],
        ]);

        $notifications = AlertNotification::query()
            ->whereHas('alert', fn ($q) => $q->whereIn('monitoring_target_id', $targets->modelKeys()))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['channel'] ?? null, fn ($q, $channel) => $q->where('channel', $channel))
            ->when($filters['webhook'] ?? null, fn ($q, $webhook) => $q->where('webhook_id', $webhook))
            ->when($filters['target'] ?? null, fn ($q, $target) => $q->whereHas('alert', fn ($a) => $a->where('monitoring_target_id', $target)))
            ->when($filters['alert'] ?? null, fn ($q, $alert) => $q->where('alert_id', $alert))
            ->with(['alert.target:id,name', 'user:id,name'])
            ->latest('created_at')->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('teams.notification-history', [
            'team' => $team,
            'notifications' => $notifications,
            'filters' => $filters,
            'targets' => $targets,
            'statuses' => NotificationStatus::cases(),
            'channels' => [NotificationChannel::Email, NotificationChannel::Webhook],
            'webhooks' => $team->webhooks()->orderBy('name')->get(['id', 'name']),
            'alert' => isset($filters['alert']) ? Alert::with('target:id,name')->find($filters['alert']) : null,
            'showEmails' => $request->user()->hasPermission(Permission::UserView, $team),
            'showErrors' => $request->user()->hasPermission(Permission::ConfigurationManage, $team),
            'teams' => $request->user()->teams()->orderBy('name')->get(),
        ]);
    }
}
