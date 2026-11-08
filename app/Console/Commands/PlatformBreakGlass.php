<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Compte de secours (« break-glass », SCRUM-90) : désigne un Super Admin qui garde le mot de passe et la 2FA de la
 * plateforme quand SSO_REQUIRED=true, pour entrer si le fournisseur d'identité est en panne (URL /login/break-glass).
 * Un compte sans mot de passe (créé par SSO) en reçoit un, saisi ici en interactif.
 */
class PlatformBreakGlass extends Command
{
    protected $signature = 'platform:break-glass {email : Super Admin à désigner} {--revoke : retirer la désignation}';

    protected $description = 'Désigne (ou retire) le compte de secours utilisable quand le SSO est obligatoire';

    public function handle(): int
    {
        $user = User::where('email', strtolower(trim($this->argument('email'))))->first();
        if ($user === null) {
            $this->components->error('No account with this email.');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            if ($user->break_glass_at === null) {
                $this->components->info("{$user->email} is not an emergency access account.");

                return self::SUCCESS;
            }
            $user->forceFill(['break_glass_at' => null])->save();
            AuditLog::record('platform.break_glass_revoke', null, $user, ['email' => $user->email, 'via' => 'console']);
            $this->components->info("{$user->email} is no longer an emergency access account.");

            return self::SUCCESS;
        }

        if (! $user->isSuperAdmin() || ! $user->isActive()) {
            $this->components->error('The emergency access account must be an active Super Admin (platform:create-admin).');

            return self::FAILURE;
        }

        if (! $user->hasPassword() && ! $this->setPassword($user)) {
            return self::FAILURE;
        }

        $user->forceFill(['break_glass_at' => now()])->save();
        AuditLog::record('platform.break_glass_grant', null, $user, ['email' => $user->email, 'via' => 'console']);

        $this->components->info("{$user->email} is now the emergency access account: ".route('break-glass'));
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            $this->components->warn('No two-factor authentication yet: it will be required at the first emergency login.');
        }

        return self::SUCCESS;
    }

    /** Compte créé par SSO : mot de passe saisi en interactif, jamais en argument (historique du shell) */
    private function setPassword(User $user): bool
    {
        if (! $this->input->isInteractive()) {
            $this->components->error('This account has no password. Run the command without --no-interaction to choose one.');

            return false;
        }

        $this->components->warn('This account has no password (created by single sign-on). Choose one for emergency access.');
        $password = (string) $this->secret('Password (hidden)');
        $confirmation = (string) $this->secret('Confirm the password');
        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'string', Password::default(), 'confirmed']],
        );
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return false;
        }

        $user->forceFill(['password' => $password])->save();

        return true;
    }
}
