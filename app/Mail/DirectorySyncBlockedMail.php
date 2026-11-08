<?php

namespace App\Mail;

use App\Models\Team;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Synchronisation des groupes de l'annuaire refusée : la team aurait perdu son dernier Admin actif (SCRUM-92) */
class DirectorySyncBlockedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Team $team, public User $account, public string $group, public string $reason) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Directory sync refused for {$this->team->name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.directory-sync-blocked', with: [
            'team' => $this->team->name,
            'name' => $this->account->name,
            'email' => $this->account->email,
            'group' => $this->group,
            'reason' => $this->reason,
            'url' => route('admin.teams.show', $this->team),
        ]);
    }
}
