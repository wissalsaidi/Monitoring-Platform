<?php

namespace App\Console\Commands;

use App\Exceptions\PlatformAdminException;
use App\Models\User;
use App\Services\PlatformAdminService;
use Illuminate\Console\Command;

/**
 * Retire le rôle Super Admin (SCRUM-81). Refuse de retirer le dernier : la plateforme ne doit jamais rester sans
 * administrateur. Le compte et ses teams sont gardés.
 */
class PlatformRevokeAdmin extends Command
{
    protected $signature = 'platform:revoke-admin {email}';

    protected $description = 'Retire le rôle Super Admin d\'un compte (jamais le dernier)';

    public function handle(PlatformAdminService $admins): int
    {
        $user = User::where('email', strtolower(trim($this->argument('email'))))->first();

        if ($user === null) {
            $this->components->error('No account with this email.');

            return self::FAILURE;
        }

        try {
            $admins->revoke($user);
        } catch (PlatformAdminException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("{$user->email} is no longer a Super Admin.");

        return self::SUCCESS;
    }
}
