<?php

namespace App\Services;

use App\Enums\RegistrationMode;
use App\Exceptions\SsoAccessException;
use App\Models\AuditLog;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compte de la plateforme pour une identité SSO vérifiée (SCRUM-89), mêmes règles d'accès que SCRUM-82 et SCRUM-83 :
 *
 * 1. identité déjà liée (iss + sub) : ce compte ;
 * 2. compte existant avec le même email : liaison, seulement si l'annuaire atteste l'email ;
 * 3. invitation en attente pour cet email : compte créé sans mot de passe, dans la team avec le rôle invité ;
 * 4. personne inconnue : compte créé sans team en mode domain (domaine autorisé) ou open ; refus en mode invite.
 *
 * L'email d'un compte n'est jamais changé par l'annuaire : un écart est signalé dans l'audit, pas appliqué.
 */
class SsoAccountService
{
    public const FAILED = 'Single sign-on failed. Try again, or contact your administrator.';

    public const EMAIL_UNVERIFIED = 'Your company directory does not confirm your email address, so it cannot be used to sign in here. Contact your administrator.';

    public const NO_ACCOUNT = 'No account on this platform matches your company account. Ask your administrator for an invitation.';

    public function __construct(private TeamMemberService $members) {}

    /**
     * @param  array<string, mixed>  $claims  claims vérifiés du jeton d'identité
     *
     * @throws SsoAccessException
     */
    public function resolve(array $claims): User
    {
        $issuer = (string) $claims['iss'];
        $subject = (string) $claims['sub'];
        $email = is_string($claims['email'] ?? null) && filter_var($claims['email'], FILTER_VALIDATE_EMAIL)
            ? Str::lower($claims['email']) : null;

        $linked = User::where('oidc_issuer', $issuer)->where('oidc_subject', $subject)->first();
        if ($linked !== null) {
            $this->reportEmailChange($linked, $email);

            return $linked;
        }

        if ($email === null) {
            throw new SsoAccessException('No usable email claim.', self::FAILED);
        }
        if (! $this->emailIsVerified($claims)) {
            throw new SsoAccessException('Email not verified by the provider.', self::EMAIL_UNVERIFIED);
        }

        $existing = User::where('email', $email)->first();
        if ($existing !== null) {
            return $this->link($existing, $issuer, $subject);
        }

        $invitations = TeamInvitation::with('team')->where('email', $email)->where('expires_at', '>', now())->get();
        $mode = RegistrationMode::current();
        if ($invitations->isEmpty() && ! $mode->acceptsEmail($email)) {
            throw new SsoAccessException("Unknown person, registration mode {$mode->value}.", self::NO_ACCOUNT, $email);
        }

        return $this->create($claims, $email, $issuer, $subject, $invitations, $mode);
    }

    /** Fournisseur : l'email du jeton peut-il servir à retrouver ou créer un compte ? */
    private function emailIsVerified(array $claims): bool
    {
        return ($claims['email_verified'] ?? null) === true || config('oidc.trust_email');
    }

    /** Première connexion SSO d'un compte existant ; un compte désactivé est rendu sans liaison (refusé ensuite) */
    private function link(User $user, string $issuer, string $subject): User
    {
        // Déjà lié à une autre identité : jamais repris par l'email
        if ($user->oidc_subject !== null) {
            throw new SsoAccessException("Account #{$user->id} is linked to another identity.", self::FAILED);
        }
        if (! $user->isActive()) {
            return $user;
        }

        $user->forceFill(['oidc_issuer' => $issuer, 'oidc_subject' => $subject])->save();
        AuditLog::record('user.sso_link', $user, $user, ['provider' => self::providerName($issuer)]);

        return $user;
    }

    /**
     * Compte créé depuis le SSO : nom et email du jeton, email vérifié d'office, pas de mot de passe local.
     *
     * @param  Collection<int, TeamInvitation>  $invitations
     */
    private function create(array $claims, string $email, string $issuer, string $subject, Collection $invitations, RegistrationMode $mode): User
    {
        try {
            return DB::transaction(function () use ($claims, $email, $issuer, $subject, $invitations, $mode) {
                $user = User::forceCreate([
                    'name' => self::nameFrom($claims, $email),
                    'email' => $email,
                    'password' => null,
                    'email_verified_at' => now(),
                    'oidc_issuer' => $issuer,
                    'oidc_subject' => $subject,
                ]);
                AuditLog::record('user.create', $user, $user, [
                    'email' => $email,
                    'via' => 'sso',
                    'provider' => self::providerName($issuer),
                    ...($invitations->isEmpty() ? ['mode' => $mode->value] : ['team' => $invitations->pluck('team.name')->implode(', ')]),
                ]);
                foreach ($invitations as $invitation) {
                    $this->members->accept($invitation, $user);
                }

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Deux retours simultanés pour la même personne : le premier a créé le compte
            throw new SsoAccessException('Account created concurrently.', self::FAILED);
        }
    }

    /** L'annuaire donne un autre email : signalé une fois par nouvelle adresse, jamais appliqué */
    private function reportEmailChange(User $user, ?string $email): void
    {
        if ($email === null || $email === Str::lower($user->email)) {
            return;
        }

        $last = AuditLog::where('action', 'user.sso_email_mismatch')
            ->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)
            ->latest('id')->first();
        if (($last?->details['directory_email'] ?? null) === $email) {
            return;
        }

        AuditLog::record('user.sso_email_mismatch', $user, $user, ['email' => $user->email, 'directory_email' => $email]);
    }

    /** name, sinon prénom + nom, sinon identifiant, sinon le début de l'email */
    private static function nameFrom(array $claims, string $email): string
    {
        $given = trim(((string) ($claims['given_name'] ?? '')).' '.((string) ($claims['family_name'] ?? '')));
        $name = collect([$claims['name'] ?? null, $given, $claims['preferred_username'] ?? null])
            ->first(fn ($value) => is_string($value) && trim($value) !== '');

        return Str::limit(trim($name ?? Str::before($email, '@')), 255, '');
    }

    /** Nom du fournisseur montré dans l'audit et sur « My account » : son hôte (ex. login.microsoftonline.com) */
    public static function providerName(?string $issuer): ?string
    {
        return $issuer === null ? null : (parse_url($issuer, PHP_URL_HOST) ?: $issuer);
    }
}
