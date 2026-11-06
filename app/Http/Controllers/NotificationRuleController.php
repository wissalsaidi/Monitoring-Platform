<?php

namespace App\Http\Controllers;

use App\Enums\AlertSeverity;
use App\Enums\NotificationChannel;
use App\Enums\NotificationEvent;
use App\Enums\Permission;
use App\Models\AlertRule;
use App\Models\AuditLog;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Page « Notifications » (cahier des charges §24 et §44, SCRUM-55) : pour chaque sévérité, email ou dashboard
 * seulement, à l'ouverture et à la résolution. Tout membre voit les règles ; Admin et Manager les modifient
 * (permission notification.manage). Chaque modification est tracée (notification_rule.update).
 */
class NotificationRuleController extends Controller
{
    /** Lien du menu : la team par défaut de l'utilisateur */
    public function current(Request $request): RedirectResponse
    {
        $team = $request->user()->defaultTeam();

        abort_if($team === null, 403);

        return redirect()->route('teams.notifications.index', $team);
    }

    public function index(Request $request, Team $team): View
    {
        Gate::authorize(Permission::AlertView->value, $team);

        return view('teams.notifications', [
            'team' => $team,
            'channels' => $this->channels($team),
            'severities' => $this->severities(),
            'canManage' => $request->user()->hasPermission(Permission::NotificationManage, $team),
            'teams' => $request->user()->teams()->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize(Permission::NotificationManage->value, $team);

        $rules = [];
        foreach (AlertSeverity::cases() as $severity) {
            foreach (NotificationEvent::cases() as $event) {
                $rules["rules.{$severity->value}.{$event->value}"] = ['required', Rule::enum(NotificationChannel::class)->only(NotificationChannel::ruleChoices())];
            }
        }
        $data = $request->validate($rules)['rules'];

        // Ordre de la page (CRITICAL d'abord) : l'audit se lit comme le formulaire
        $changes = DB::transaction(function () use ($team, $data) {
            $existing = $team->alertRules()->lockForUpdate()->get()->keyBy(fn (AlertRule $rule) => $rule->key());
            $changes = [];

            foreach ($this->severities() as $severity) {
                foreach (NotificationEvent::cases() as $event) {
                    $channel = NotificationChannel::from($data[$severity->value][$event->value]);
                    $rule = $existing["{$severity->value}_{$event->value}"] ?? null;

                    if ($rule?->channel === $channel) {
                        continue;
                    }

                    $changes["{$severity->value}_{$event->value}"] = [$rule?->channel->label(), $channel->label()];
                    $team->alertRules()->updateOrCreate(
                        ['severity' => $severity->value, 'event' => $event->value],
                        ['channel' => $channel->value],
                    );
                }
            }

            return $changes;
        });

        if ($changes === []) {
            return back()->with('success', 'No change.');
        }

        AuditLog::record('notification_rule.update', $request->user(), $team, ['changes' => $changes]);

        return back()->with('success', 'Notification rules saved.');
    }

    /**
     * Canal de chaque règle ; une règle absente vaut « dashboard seulement » (le routeur n'envoie rien sans règle).
     *
     * @return array<string, array<string, NotificationChannel>>
     */
    private function channels(Team $team): array
    {
        $existing = $team->alertRules()->get()->keyBy(fn (AlertRule $rule) => $rule->key());
        $channels = [];

        foreach (AlertSeverity::cases() as $severity) {
            foreach (NotificationEvent::cases() as $event) {
                $channels[$severity->value][$event->value] = $existing["{$severity->value}_{$event->value}"]?->channel
                    ?? NotificationChannel::Dashboard;
            }
        }

        return $channels;
    }

    /** @return list<AlertSeverity> de la plus grave à la moins grave */
    private function severities(): array
    {
        return collect(AlertSeverity::cases())->sortByDesc(fn (AlertSeverity $s) => $s->rank())->values()->all();
    }
}
