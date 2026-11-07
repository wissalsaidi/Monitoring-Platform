<?php

namespace App\Mail;

use App\Models\Webhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Prévient les Admin d'une team qu'un webhook a été désactivé après trop d'échecs (SCRUM-79). Jamais l'URL. */
class WebhookDisabledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Webhook $webhook, public ?string $lastError = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Webhook “{$this->webhook->name}” disabled after {$this->webhook->consecutive_failures} failed deliveries");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.webhook-disabled', with: [
            'name' => $this->webhook->name,
            'type' => $this->webhook->type->label(),
            'team' => $this->webhook->team->name,
            'failures' => $this->webhook->consecutive_failures,
            'lastError' => $this->lastError,
            'url' => route('teams.notifications.webhooks.index', $this->webhook->team),
            'history' => route('teams.notifications.history', [$this->webhook->team, 'webhook' => $this->webhook->id]),
        ]);
    }
}
