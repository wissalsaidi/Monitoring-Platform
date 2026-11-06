<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Prévient un Super Admin qu'un compte vérifié attend une team (SCRUM-82) */
class NewAccountMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $account) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "New account waiting for access: {$this->account->email}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.new-account', with: [
            'name' => $this->account->name,
            'email' => $this->account->email,
            'created' => $this->account->created_at->toDisplay()->format('d/m/Y H:i'),
        ]);
    }
}
