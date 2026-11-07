<?php

namespace App\Http\Controllers;

use App\Enums\AlertSeverity;
use App\Enums\NotificationEvent;
use App\Enums\Permission;
use App\Enums\WebhookType;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\Webhook;
use App\Rules\WebhookUrl;
use App\Services\WebhookTester;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Onglet « Webhooks » de la page Notifications (SCRUM-78). Tout membre voit la liste, sans les adresses ;
 * Admin et Manager (notification.manage) créent, modifient, désactivent, suppriment et testent.
 *
 * L'URL n'est jamais réaffichée en entier (le chemin d'un webhook Slack ou Teams vaut un mot de passe) et le secret de
 * signature n'est montré qu'une fois, juste après la création ou la régénération. L'audit ne contient ni l'un ni l'autre.
 */
class WebhookController extends Controller
{
    /** Garde-fou : chaque webhook est appelé à chaque alerte de la team */
    public const MAX_PER_TEAM = 10;

    public function index(Request $request, Team $team): View
    {
        Gate::authorize(Permission::AlertView->value, $team);

        return view('teams.webhooks', [
            'team' => $team,
            'webhooks' => $team->webhooks()->with('lastNotification')->orderBy('name')->get(),
            'canManage' => $request->user()->hasPermission(Permission::NotificationManage, $team),
            'teams' => $request->user()->teams()->orderBy('name')->get(),
            'max' => self::MAX_PER_TEAM,
        ]);
    }

    public function store(Request $request, Team $team): RedirectResponse
    {
        Gate::authorize(Permission::NotificationManage->value, $team);

        if ($team->webhooks()->count() >= self::MAX_PER_TEAM) {
            return back()->with('error', 'A team can have at most '.self::MAX_PER_TEAM.' webhooks.');
        }

        $webhook = $team->webhooks()->create($this->validated($request, $team));

        AuditLog::record('webhook.create', $request->user(), $webhook, [
            'name' => $webhook->name, 'type' => $webhook->type->label(),
            'severities' => $webhook->severitiesLabel(), 'events' => $webhook->eventsLabel(),
        ]);

        return redirect()->route('teams.notifications.webhooks.index', $team)
            ->with('success', "Webhook “{$webhook->name}” created.")
            ->with('webhook_secret', $this->secretToShow($webhook));
    }

    public function edit(Request $request, Team $team, Webhook $webhook): View
    {
        Gate::authorize(Permission::NotificationManage->value, $team);

        return view('teams.webhook-edit', ['team' => $team, 'webhook' => $webhook]);
    }

    public function update(Request $request, Team $team, Webhook $webhook): RedirectResponse
    {
        Gate::authorize(Permission::NotificationManage->value, $team);

        $data = $this->validated($request, $team, $webhook);
        $before = $this->auditedFields($webhook);

        // URL laissée vide : l'adresse actuelle est gardée (elle n'est jamais renvoyée au formulaire)
        if (($data['url'] ?? '') === '') {
            unset($data['url']);
        }
        $webhook->fill($data);
        $urlChanged = $webhook->isDirty('url');
        if ($webhook->isDirty('active') && $webhook->active) {
            $webhook->consecutive_failures = 0;
        }
        $webhook->save();

        $changes = [];
        foreach ($this->auditedFields($webhook) as $field => $value) {
            if ($before[$field] !== $value) {
                $changes[$field] = [$before[$field], $value];
            }
        }
        if ($changes === [] && ! $urlChanged) {
            return redirect()->route('teams.notifications.webhooks.index', $team)->with('success', 'No change.');
        }

        // Le nom reste lisible dans l'audit après une suppression du webhook
        AuditLog::record('webhook.update', $request->user(), $webhook, array_filter([
            'name' => $webhook->name,
            'changes' => $changes ?: null,
            'URL' => $urlChanged ? 'changed' : null,
        ]));

        return redirect()->route('teams.notifications.webhooks.index', $team)->with('success', "Webhook “{$webhook->name}” saved.");
    }

    /** Désactiver / réactiver depuis la liste ; une réactivation remet le compteur d'échecs à 0 */
    public function toggle(Request $request, Team $team, Webhook $webhook): RedirectResponse
    {
        Gate::authorize(Permission::NotificationManage->value, $team);

        $webhook->active = ! $webhook->active;
        if ($webhook->active) {
            $webhook->consecutive_failures = 0;
        }
        $webhook->save();

        AuditLog::record('webhook.update', $request->user(), $webhook, [
            'name' => $webhook->name,
            'changes' => ['active' => [! $webhook->active, $webhook->active]],
        ]);

        return back()->with('success', "Webhook “{$webhook->name}” ".($webhook->active ? 'enabled.' : 'disabled.'));
    }

    public function destroy(Request $request, Team $team, Webhook $webhook): RedirectResponse
    {
        Gate::authorize(Permission::NotificationManage->value, $team);

        // L'historique des envois est gardé (webhook_id passe à null, le nom reste dans « recipient »)
        AuditLog::record('webhook.delete', $request->user(), $webhook, ['name' => $webhook->name, 'type' => $webhook->type->label()]);
        $webhook->delete();

        return redirect()->route('teams.notifications.webhooks.index', $team)->with('success', "Webhook “{$webhook->name}” deleted.");
    }

    /** Nouveau secret de signature (webhook générique) : l'ancien cesse de valoir immédiatement */
    public function rotateSecret(Request $request, Team $team, Webhook $webhook): RedirectResponse
    {
        Gate::authorize(Permission::NotificationManage->value, $team);
        abort_unless($webhook->type === WebhookType::Generic, 404);

        $webhook->forceFill(['secret' => Str::random(48)])->save();
        AuditLog::record('webhook.secret_rotate', $request->user(), $webhook, ['name' => $webhook->name]);

        return back()
            ->with('success', "New secret for “{$webhook->name}”. Update the receiver: the previous secret no longer works.")
            ->with('webhook_secret', $this->secretToShow($webhook));
    }

    /** « Send test » : envoi immédiat d'une alerte fictive, résultat affiché sur la ligne du webhook */
    public function test(Request $request, Team $team, Webhook $webhook, WebhookTester $tester): RedirectResponse
    {
        Gate::authorize(Permission::NotificationManage->value, $team);

        return back()->with('webhook_test', ['id' => $webhook->id, ...$tester->send($webhook)]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, Team $team, ?Webhook $webhook = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('webhooks', 'name')->where('team_id', $team->id)->ignore($webhook)],
            'type' => ['required', Rule::enum(WebhookType::class)],
            'url' => [$webhook ? 'nullable' : 'required', 'string', 'max:2000', new WebhookUrl],
            'severities' => ['required', 'array', 'min:1'],
            'severities.*' => [Rule::enum(AlertSeverity::class)],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => [Rule::enum(NotificationEvent::class)],
            'active' => ['sometimes', 'boolean'],
        ], [
            'severities.required' => 'Choose at least one severity.',
            'events.required' => 'Choose at least one event.',
        ]);

        $data['severities'] = array_values(array_unique($data['severities']));
        $data['events'] = array_values(array_unique($data['events']));
        $data['active'] = $request->boolean('active', $webhook === null);

        return $data;
    }

    /** Champs suivis par l'audit, en texte lisible (jamais l'URL ni le secret) */
    private function auditedFields(Webhook $webhook): array
    {
        return [
            'name' => $webhook->name,
            'type' => $webhook->type->label(),
            'severities' => $webhook->severitiesLabel(),
            'events' => $webhook->eventsLabel(),
            'active' => $webhook->active,
        ];
    }

    /** Secret à afficher une fois : seul le webhook générique signe ses envois */
    private function secretToShow(Webhook $webhook): ?array
    {
        return $webhook->type === WebhookType::Generic ? ['id' => $webhook->id, 'secret' => $webhook->secret] : null;
    }
}
