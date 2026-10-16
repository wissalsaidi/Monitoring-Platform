<?php

namespace App\Models;

use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * Invitation en attente (SCRUM-59). Supprimée quand elle est acceptée ou annulée.
 */
class TeamInvitation extends Model
{
    public const VALID_DAYS = 7;

    protected $fillable = ['team_id', 'email', 'role', 'invited_by', 'expires_at'];

    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
            'expires_at' => 'datetime',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /** Lien envoyé par email : signé, il expire en même temps que l'invitation */
    public function acceptUrl(): string
    {
        return URL::temporarySignedRoute('invitations.accept', $this->expires_at, ['invitation' => $this->id]);
    }
}
