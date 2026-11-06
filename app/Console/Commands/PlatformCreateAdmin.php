<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\PlatformAdminService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Premier Super Admin de la plateforme, ou promotion d'un compte existant (SCRUM-81). Seul moyen de créer un
 * Super Admin sans en être déjà un : jamais par le web. Le mot de passe est demandé en saisie masquée, jamais
 * en argument (il resterait dans l'historique du shell et la liste des processus).
 */
class PlatformCreateAdmin extends Command
{
    protected $signature = 'platform:create-admin {email : adresse du Super Admin (compte existant ou nouveau)}';

    protected $description = 'Crée un Super Admin de la plateforme, ou promeut un compte existant';

    public function handle(PlatformAdminService $admins): int
    {
        $email = strtolower(trim($this->argument('email')));
        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:255']])->fails()) {
            $this->components->error('Invalid email address.');

            return self::FAILURE;
        }

        $user = User::where('email', $email)->first();

        if ($user !== null) {
            if ($user->isSuperAdmin()) {
                $this->components->info("{$email} is already a Super Admin.");

                return self::SUCCESS;
            }
            if (! $this->confirm("Promote the existing account {$user->name} <{$email}> to Super Admin?", true)) {
                $this->components->warn('Nothing changed.');

                return self::FAILURE;
            }

            $admins->grant($user);
            $this->components->info("{$email} is now a Super Admin.");

            return self::SUCCESS;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('A new account needs a password typed interactively: run the command without --no-interaction.');

            return self::FAILURE;
        }

        $name = trim((string) $this->ask('Name'));
        $password = (string) $this->secret('Password (hidden)');
        $confirmation = (string) $this->secret('Confirm the password');

        $validator = Validator::make(
            ['name' => $name, 'password' => $password, 'password_confirmation' => $confirmation],
            ['name' => ['required', 'string', 'max:255'], 'password' => ['required', 'string', Password::default(), 'confirmed']],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        // Compte créé par l'exploitant : email considéré vérifié, aucune team (il s'ajoute à une team si besoin)
        DB::transaction(function () use ($name, $email, $password, $admins) {
            $user = User::forceCreate([
                'name' => $name,
                'email' => $email,
                'password' => $password,
                'email_verified_at' => now(),
            ]);
            AuditLog::record('user.create', null, $user, ['email' => $email, 'via' => 'console']);
            $admins->grant($user);
        });

        $this->components->info("Super Admin {$email} created. They can log in now; they belong to no team yet.");

        return self::SUCCESS;
    }
}
