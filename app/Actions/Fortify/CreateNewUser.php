<?php

namespace App\Actions\Fortify;

use App\Enums\RegistrationMode;
use App\Models\AuditLog;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Inscription publique (SCRUM-82) : seulement en mode domain ou open. Le compte est créé sans team ;
     * l'utilisateur attend sur « Waiting for access » qu'un Admin l'ajoute.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $mode = RegistrationMode::current();
        abort_unless($mode->allowsSignUp(), 404);

        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique(User::class),
                function (string $attribute, mixed $value, Closure $fail) use ($mode) {
                    if (is_string($value) && ! $mode->acceptsEmail($value)) {
                        $fail('Use your company email address ('.implode(', ', array_map(fn ($d) => '@'.$d, RegistrationMode::domains())).').');
                    }
                },
            ],
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input, $mode) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => Hash::make($input['password']),
            ]);
            AuditLog::record('user.create', $user, $user, ['email' => $user->email, 'via' => 'registration', 'mode' => $mode->value]);

            return $user;
        });
    }
}
