<?php

namespace App\Mail;

use App\Models\Alert;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Email « alerte résolue » (SCRUM-54, cahier des charges §24 « Resolved → Email »). Envoyé par le job
 * SendAlertNotification, qui lui passe la valeur maximale de l'incident si elle est connue (SCRUM-50).
 */
class AlertResolvedMail extends Mailable
{
    public function __construct(public Alert $alert, public ?float $peak = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[RESOLVED] {$this->alert->message}");
    }

    public function content(): Content
    {
        $target = $this->alert->target;
        $format = fn ($date) => $date->toDisplay()->format('d/m/Y H:i:s T');

        return new Content(markdown: 'mail.alert-resolved', with: [
            'alert' => $this->alert,
            'target' => $target,
            'team' => $target->team->name,
            'started' => $format($this->alert->started_at),
            'resolved' => $format($this->alert->resolved_at ?? now()),
            'duration' => $this->alert->durationForHumans(),
            'maximum' => $this->alert->peakLabel($this->peak),   // « peak » serait remplacé par la propriété publique
            'url' => route('targets.show', [$target, 'tab' => 'history']),
        ]);
    }
}
