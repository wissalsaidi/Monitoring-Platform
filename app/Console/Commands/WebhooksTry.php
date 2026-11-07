<?php

namespace App\Console\Commands;

use App\Enums\AlertStatus;
use App\Enums\NotificationEvent;
use App\Enums\WebhookType;
use App\Exceptions\WebhookDeliveryException;
use App\Models\Alert;
use App\Models\Webhook;
use App\Rules\WebhookUrl;
use App\Services\WebhookPayload;
use App\Services\WebhookSender;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Essai d'une URL de webhook (SCRUM-77) : envoie un message d'exemple, construit sur la dernière alerte, dans le format
 * du type choisi. L'URL est saisie masquée (elle vaut un mot de passe pour Slack et Teams) et n'est jamais enregistrée.
 */
class WebhooksTry extends Command
{
    protected $signature = 'webhooks:try {type : generic, slack ou teams} {--alert= : id de l\'alerte à utiliser (par défaut la dernière)} {--resolved : message de résolution}';

    protected $description = 'Envoie un message d\'exemple vers une URL de webhook (URL saisie masquée, non enregistrée)';

    public function handle(WebhookSender $sender): int
    {
        $type = WebhookType::tryFrom((string) $this->argument('type'));
        if ($type === null) {
            $this->components->error('Type: generic, slack or teams.');

            return self::FAILURE;
        }

        $alert = $this->option('alert') ? Alert::find($this->option('alert')) : Alert::latest('id')->first();
        if ($alert === null) {
            $this->components->error('No alert to build the sample message from.');

            return self::FAILURE;
        }

        $url = (string) $this->secret('Webhook URL (hidden)');
        $validator = Validator::make(['url' => $url], ['url' => ['required', new WebhookUrl]]);
        if ($validator->fails()) {
            $this->components->error($validator->errors()->first('url'));

            return self::FAILURE;
        }

        // Webhook en mémoire seulement : rien n'est enregistré
        $webhook = (new Webhook)->forceFill(['name' => 'Try', 'type' => $type, 'url' => $url, 'secret' => Str::random(48)]);
        $event = $this->option('resolved') ? NotificationEvent::Resolved : NotificationEvent::Opened;
        if ($event === NotificationEvent::Resolved && $alert->status !== AlertStatus::Resolved) {
            $alert->resolved_at ??= now();
        }
        $deliveryId = (string) Str::uuid();

        try {
            $status = $sender->send($webhook, WebhookPayload::eventName($event), WebhookPayload::forWebhook($webhook, $alert, $event, $deliveryId), $deliveryId);
        } catch (WebhookDeliveryException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("{$type->label()} message sent (HTTP {$status}), built from alert #{$alert->id}.");

        return self::SUCCESS;
    }
}
