<?php

namespace App\Mail;

use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TeamInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public TeamInvitation $invitation) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Join the {$this->invitation->team->name} team on ".config('app.name'));
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.team-invitation', with: [
            'url' => $this->invitation->acceptUrl(),
            'team' => $this->invitation->team->name,
            'inviter' => $this->invitation->inviter?->name ?? 'An administrator',
            'role' => ucfirst($this->invitation->role->value),
            'expires' => $this->invitation->expires_at->toDisplay()->format('d/m/Y H:i'),
        ]);
    }
}
