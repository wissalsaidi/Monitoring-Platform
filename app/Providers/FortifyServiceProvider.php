<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Enums\RegistrationMode;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::loginView(fn () => view('auth.login'));

        // Connexion (SCRUM-84) : un compte désactivé est refusé, seulement après un mot de passe correct
        Fortify::authenticateUsing(function (Request $request) {
            $user = User::where('email', Str::lower((string) $request->input(Fortify::username())))->first();
            if ($user === null || ! Hash::check((string) $request->input('password'), $user->password)) {
                return null;
            }
            if (! $user->isActive()) {
                throw ValidationException::withMessages([Fortify::username() => 'This account has been deactivated. Contact your administrator.']);
            }

            return $user;
        });
        // Inscription (SCRUM-82) : 404 en mode invite, comme si la page n'existait pas (le POST est refusé dans CreateNewUser)
        Fortify::registerView(function () {
            abort_unless(RegistrationMode::current()->allowsSignUp(), 404);

            return view('auth.register', ['domains' => RegistrationMode::current() === RegistrationMode::Domain ? RegistrationMode::domains() : []]);
        });
        Fortify::requestPasswordResetLinkView(fn () => view('auth.forgot-password'));
        Fortify::resetPasswordView(fn ($request) => view('auth.reset-password', ['request' => $request]));
        Fortify::verifyEmailView(fn () => view('auth.verify-email'));

        // Rate limiting de la connexion : 5 essais par minute par couple email + IP (cahier des charges §29)
        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
