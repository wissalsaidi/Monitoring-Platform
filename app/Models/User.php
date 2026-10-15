<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\TeamRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'team_members')
            ->using(TeamMember::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function belongsToTeam(Team $team): bool
    {
        return $this->teams()->whereKey($team->id)->exists();
    }

    /** @var array<int, ?TeamRole> rôles déjà lus, par team (évite une requête par ligne dans les listes) */
    private array $rolesByTeam = [];

    /** Rôle de l'utilisateur dans une team, null s'il n'en est pas membre. */
    public function roleIn(Team $team): ?TeamRole
    {
        if (! array_key_exists($team->id, $this->rolesByTeam)) {
            $this->rolesByTeam[$team->id] = $this->teams()->whereKey($team->id)->first()?->pivot->role;
        }

        return $this->rolesByTeam[$team->id];
    }

    /**
     * Team utilisée par défaut, par exemple pour créer une target (MVP : une team par utilisateur).
     */
    public function defaultTeam(): ?Team
    {
        return $this->teams()->oldest('team_members.created_at')->first();
    }
}
