<?php

namespace App\Services;

use App\Enums\AlertSeverity;
use App\Enums\NotificationEvent;
use App\Models\Alert;

/**
 * Messages lisibles dans un canal (SCRUM-77) : Slack (blocs dans une pièce jointe colorée) et Microsoft Teams
 * (Adaptive Card pour le workflow « Post to a channel when a webhook request is received »). Même contenu que les
 * emails : target, métrique, valeur / seuil, début ; à la résolution, la durée et le maximum ; un bouton vers l'alerte.
 */
class WebhookMessage
{
    /** Couleurs de la barre Slack, par sévérité ; vert pour une résolution */
    public const COLORS = [
        'critical' => '#d32f2f',
        'high' => '#f57c00',
        'warning' => '#fbc02d',
        'info' => '#1976d2',
        'resolved' => '#2e7d32',
    ];

    /** @return array<string, mixed> */
    public static function slack(Alert $alert, NotificationEvent $event, ?float $peak = null): array
    {
        $resolved = $event === NotificationEvent::Resolved;

        return [
            // Texte de repli : notification mobile, aperçu, lecteurs d'écran
            'text' => self::title($alert, $event),
            'attachments' => [[
                'color' => self::COLORS[$resolved ? 'resolved' : $alert->severity->value],
                'blocks' => [
                    ['type' => 'header', 'text' => ['type' => 'plain_text', 'text' => self::title($alert, $event), 'emoji' => true]],
                    ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => self::escapeSlack($alert->message)]],
                    ['type' => 'section', 'fields' => array_map(
                        fn (array $fact) => ['type' => 'mrkdwn', 'text' => '*'.$fact['title']."*\n".self::escapeSlack($fact['value'])],
                        self::facts($alert, $event, $peak),
                    )],
                    ['type' => 'actions', 'elements' => [[
                        'type' => 'button',
                        'text' => ['type' => 'plain_text', 'text' => 'View alert'],
                        'url' => self::url($alert, $event),
                        ...($resolved ? [] : ['style' => 'danger']),
                    ]]],
                    ['type' => 'context', 'elements' => [['type' => 'mrkdwn', 'text' => 'Team *'.self::escapeSlack($alert->target->team->name).'* · '.config('app.name')]]],
                ],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    public static function teams(Alert $alert, NotificationEvent $event, ?float $peak = null): array
    {
        $resolved = $event === NotificationEvent::Resolved;

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'msteams' => ['width' => 'Full'],
                    'body' => [
                        [
                            'type' => 'TextBlock', 'text' => self::title($alert, $event), 'weight' => 'Bolder', 'size' => 'Medium',
                            'wrap' => true, 'color' => $resolved ? 'Good' : self::teamsColor($alert->severity),
                        ],
                        ['type' => 'TextBlock', 'text' => $alert->message, 'wrap' => true],
                        ['type' => 'FactSet', 'facts' => self::facts($alert, $event, $peak)],
                        ['type' => 'TextBlock', 'text' => 'Team '.$alert->target->team->name.' · '.config('app.name'), 'isSubtle' => true, 'size' => 'Small', 'wrap' => true],
                    ],
                    'actions' => [['type' => 'Action.OpenUrl', 'title' => 'View alert', 'url' => self::url($alert, $event)]],
                ],
            ]],
        ];
    }

    /** « 🔴 [HIGH] Website-01 » à l'ouverture, « ✅ [RESOLVED] Website-01 » à la résolution */
    public static function title(Alert $alert, NotificationEvent $event): string
    {
        if ($event === NotificationEvent::Resolved) {
            return "✅ [RESOLVED] {$alert->target->name}";
        }

        $icon = match ($alert->severity) {
            AlertSeverity::Critical => '🔴',
            AlertSeverity::High => '🟠',
            AlertSeverity::Warning => '🟡',
            AlertSeverity::Info => '🔵',
        };

        return "{$icon} [{$alert->severity->label()}] {$alert->target->name}";
    }

    /**
     * Faits du message, dans l'ordre affiché (format FactSet de Teams, repris pour les champs Slack).
     *
     * @return list<array{title: string, value: string}>
     */
    public static function facts(Alert $alert, NotificationEvent $event, ?float $peak = null): array
    {
        $date = fn ($value) => $value->toDisplay()->format('d/m/Y H:i T');
        $facts = [
            ['title' => 'Target', 'value' => "{$alert->target->name} ({$alert->target->type->label()})"],
            ['title' => 'Metric', 'value' => $alert->metricLabel()],
            ['title' => 'Value / threshold', 'value' => ($alert->valueLabel() ?? '—').' / '.($alert->thresholdLabel() ?? '—')],
            ['title' => 'Started', 'value' => $date($alert->started_at)],
        ];

        if ($event === NotificationEvent::Resolved) {
            $facts[] = ['title' => 'Duration', 'value' => $alert->durationForHumans()];
            $facts[] = ['title' => 'Maximum', 'value' => $alert->peakLabel($peak ?? $alert->peak_value) ?? '—'];
        }

        return $facts;
    }

    private static function url(Alert $alert, NotificationEvent $event): string
    {
        return route('targets.show', [$alert->target, 'tab' => $event === NotificationEvent::Resolved ? 'history' : 'alerts']);
    }

    /** Couleur d'un TextBlock d'Adaptive Card (pas de couleur libre) */
    private static function teamsColor(AlertSeverity $severity): string
    {
        return match ($severity) {
            AlertSeverity::Critical, AlertSeverity::High => 'Attention',
            AlertSeverity::Warning => 'Warning',
            AlertSeverity::Info => 'Accent',
        };
    }

    /** Texte venu des targets (nom, message) : &, < et > sont des caractères de contrôle pour Slack */
    private static function escapeSlack(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
