<?php

namespace App\Mail;

use App\Models\Alert;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email « alerte ouverte » (SCRUM-53, cahier des charges §12 et §23). Envoyé par le job SendAlertNotification, déjà
 * dans la queue : ce mailable n'est pas lui-même mis en queue (sinon le job ne saurait pas si l'envoi a réussi).
 */
class AlertOpenedMail extends Mailable
{
    public function __construct(public Alert $alert) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[{$this->alert->severity->label()}] {$this->alert->message}");
    }

    public function content(): Content
    {
        $target = $this->alert->target;

        return new Content(markdown: 'mail.alert-opened', with: [
            'alert' => $this->alert,
            'target' => $target,
            'team' => $target->team->name,
            'started' => $this->alert->started_at->toDisplay()->format('d/m/Y H:i:s T'),
            'url' => route('targets.show', [$target, 'tab' => 'alerts']),
        ]);
    }
}
