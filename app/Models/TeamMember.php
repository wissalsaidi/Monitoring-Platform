<?php

namespace App\Models;

use App\Enums\TeamRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

class TeamMember extends Pivot
{
    protected $table = 'team_members';

    public $incrementing = true;

    protected function casts(): array
    {
        return [
            'role' => TeamRole::class,
        ];
    }
}
