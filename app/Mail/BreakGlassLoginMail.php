<?php

namespace App\Mail;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Prévient les autres Super Admin que le compte de secours vient d'être utilisé (SCRUM-90) */
class BreakGlassLoginMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public User $account, public string $ip, public CarbonInterface $at) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Emergency access used: {$this->account->email}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.break-glass-login', with: [
            'name' => $this->account->name,
            'email' => $this->account->email,
            'ip' => $this->ip,
            'at' => $this->at->toDisplay()->format('d/m/Y H:i'),
        ]);
    }
}
