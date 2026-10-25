<?php

namespace App\Models;

use App\Enums\Permission;
use App\Enums\PlatformPermission;
use App\Enums\PlatformRole;
use App\Enums\TeamRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * MustVerifyEmail : l'email doit être vérifié avant d'accéder à l'application (SCRUM-32).
 */
class User extends Authenticatable implements MustVerifyEmail
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
            'grafana_user_id' => 'integer',     // compte Grafana (SCRUM-48), créé par GrafanaAccessService
            // Rôle de plateforme (SCRUM-81) : volontairement hors $fillable, modifié seulement par PlatformAdminService
            'platform_role' => PlatformRole::class,
        ];
    }

    public function isSuperAdmin(): bool
    {
        return $this->platform_role === PlatformRole::SuperAdmin;
    }

    /** Permission de plateforme (SCRUM-81) ; ne donne aucun accès aux données d'une team */
    public function hasPlatformPermission(PlatformPermission $permission): bool
    {
        return $this->platform_role?->can($permission) ?? false;
    }

    #[Scope]
    protected function superAdmins(Builder $query): void
    {
        $query->where('platform_role', PlatformRole::SuperAdmin->value);
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

    /** L'utilisateur a-t-il cette permission dans la team ? (matrice : TeamRole::permissions, SCRUM-58) */
    public function hasPermission(Permission $permission, Team $team): bool
    {
        return $this->roleIn($team)?->can($permission) ?? false;
    }

    /**
     * Team utilisée par défaut, par exemple pour créer une target (MVP : une team par utilisateur).
     */
    public function defaultTeam(): ?Team
    {
        return $this->teams()->oldest('team_members.created_at')->first();
    }
}
