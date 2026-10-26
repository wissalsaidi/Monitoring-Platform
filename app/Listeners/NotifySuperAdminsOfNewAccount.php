<?php

namespace App\Listeners;

use App\Mail\NewAccountMail;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Mail;

/**
 * Inscription (SCRUM-82) : les Super Admin sont prévenus une fois l'email vérifié, pas avant,
 * pour qu'une adresse inventée ne leur envoie rien. Seulement pour un compte encore sans team.
 */
class NotifySuperAdminsOfNewAccount
{
    public function handle(Verified $event): void
    {
        $account = $event->user;
        if (! $account instanceof User || $account->teams()->exists()) {
            return;
        }

        User::superAdmins()->whereKeyNot($account->getKey())->each(
            fn (User $admin) => Mail::to($admin)->queue(new NewAccountMail($account))
        );
    }
}
