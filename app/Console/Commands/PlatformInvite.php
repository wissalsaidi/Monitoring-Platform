<?php

namespace App\Console\Commands;

use App\Enums\TeamRole;
use App\Exceptions\TeamMembershipException;
use App\Models\Team;
use App\Services\TeamMemberService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Invitation par la DSI (SCRUM-83), vers n'importe quelle team et avec n'importe quel rôle, en attendant la
 * console d'administration web (SCRUM-85). Même chemin qu'une invitation par un Admin : email, lien signé, audit.
 */
class PlatformInvite extends Command
{
    protected $signature = 'platform:invite {email} {team : id ou nom de la team} {--role=viewer : viewer, manager ou admin}';

    protected $description = 'Invite une personne dans une team (Super Admin)';

    public function handle(TeamMemberService $members): int
    {
        $email = strtolower(trim($this->argument('email')));
        $validator = Validator::make(['email' => $email, 'role' => $this->option('role')], [
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', Rule::enum(TeamRole::class)],
        ]);
        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        $key = (string) $this->argument('team');
        $team = ctype_digit($key) ? Team::find((int) $key) : Team::where('name', $key)->first();
        if ($team === null) {
            $this->components->error("No team \"{$key}\".");

            return self::FAILURE;
        }

        $role = TeamRole::from($this->option('role'));
        try {
            $invitation = $members->invite($team, $email, $role, null);
        } catch (TeamMembershipException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Invitation sent to {$email}: {$team->name} as ".ucfirst($role->value).
            ', valid until '.$invitation->expires_at->toDisplay()->format('d/m/Y H:i').'.');

        return self::SUCCESS;
    }
}
