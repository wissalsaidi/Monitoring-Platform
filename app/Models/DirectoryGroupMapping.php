<?php

namespace App\Models;

use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Groupe de l'annuaire → team et rôle (SCRUM-92). Jamais le rôle Super Admin : il reste attribué en ligne de commande.
 */
class DirectoryGroupMapping extends Model
{
    public const MAX_PER_TEAM = 20;

    protected $fillable = ['team_id', 'group', 'role'];

    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
