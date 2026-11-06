<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Qui peut créer un compte (SCRUM-82), réglé par REGISTRATION_MODE dans .env :
 * invite (par défaut) = pas d'inscription publique ; domain = adresses des domaines de REGISTRATION_DOMAINS ;
 * open = tout le monde (démo publique). Le compte est toujours créé sans team.
 */
enum RegistrationMode: string
{
    case Invite = 'invite';
    case Domain = 'domain';
    case Open = 'open';

    /** Une valeur inconnue retombe sur invite : en cas de faute de frappe, l'inscription reste fermée */
    public static function current(): self
    {
        return self::tryFrom(Str::lower(trim((string) config('registration.mode')))) ?? self::Invite;
    }

    public function allowsSignUp(): bool
    {
        return $this !== self::Invite;
    }

    /** @return list<string> domaines autorisés en mode domain, en minuscules (ex. company.com) */
    public static function domains(): array
    {
        return collect(explode(',', (string) config('registration.domains')))
            ->map(fn (string $domain) => Str::lower(ltrim(trim($domain), '@')))
            ->filter()->unique()->values()->all();
    }

    /** L'adresse peut-elle s'inscrire dans ce mode ? Domaine exact : sub.company.com n'est pas company.com */
    public function acceptsEmail(string $email): bool
    {
        return match ($this) {
            self::Invite => false,
            self::Open => true,
            self::Domain => in_array(Str::lower(Str::afterLast($email, '@')), self::domains(), true),
        };
    }
}
