<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Liste des Super Admin (SCRUM-81) : vérifier qui administre la plateforme avant d'en retirer un.
 */
class PlatformListAdmins extends Command
{
    protected $signature = 'platform:list-admins';

    protected $description = 'Liste les Super Admin de la plateforme';

    public function handle(): int
    {
        $admins = User::superAdmins()->orderBy('email')->get(['id', 'name', 'email', 'created_at', 'break_glass_at']);

        if ($admins->isEmpty()) {
            $this->components->warn('No Super Admin yet. Create one: php artisan platform:create-admin <email>');

            return self::SUCCESS;
        }

        // Compte de secours (SCRUM-90) : platform:break-glass
        $this->table(['#', 'Name', 'Email', 'Account created', 'Emergency access'], $admins->map(fn (User $u) => [
            $u->id, $u->name, $u->email, $u->created_at->toDisplay()->format('d/m/Y'), $u->break_glass_at ? 'yes' : '',
        ]));

        return self::SUCCESS;
    }
}
