<?php

/*
| SSO OpenID Connect (SCRUM-88, epic SCRUM-87) : un seul fournisseur par installation (Entra ID, Okta, Keycloak…).
| SSO absent tant que OIDC_ISSUER, OIDC_CLIENT_ID et OIDC_CLIENT_SECRET ne sont pas tous renseignés.
*/
return [

    // Émetteur exact du fournisseur, tel qu'il apparaît dans le claim « iss » (ex. https://login.microsoftonline.com/<tenant>/v2.0)
    'issuer' => trim((string) env('OIDC_ISSUER', '')),

    // Adresse interne pour lire la découverte, si le fournisseur n'est pas joignable par son adresse publique depuis le
    // conteneur (Keycloak de démo : http://keycloak:8080/realms/monitoring). Vide : l'émetteur lui-même.
    'internal_url' => trim((string) env('OIDC_INTERNAL_URL', '')),

    'client_id' => (string) env('OIDC_CLIENT_ID', ''),

    'client_secret' => (string) env('OIDC_CLIENT_SECRET', ''),

    'scopes' => (string) env('OIDC_SCOPES', 'openid email profile'),

    // Fournisseur qui garantit les emails de son annuaire sans envoyer le claim email_verified (Entra ID) :
    // l'email du jeton compte alors comme vérifié pour lier ou créer un compte (SCRUM-89). À n'activer que dans ce cas.
    'trust_email' => (bool) env('OIDC_TRUST_EMAIL', false),

    // SSO obligatoire (SCRUM-90) : plus de connexion par mot de passe, de réinitialisation ni d'inscription. Seul le
    // compte de secours (platform:break-glass) garde un mot de passe, sur /login/break-glass.
    'required' => (bool) env('SSO_REQUIRED', false),

    // Durée d'une session ouverte par le SSO, en minutes (8 h) : ensuite, nouveau passage par l'annuaire. Un compte
    // désactivé dans l'annuaire perd l'accès au plus tard à ce moment-là.
    'session_lifetime' => (int) env('SSO_SESSION_LIFETIME', 480),

    // MFA faite chez le fournisseur (SCRUM-90) : la 2FA de la plateforme n'est alors pas redemandée.
    // amr (RFC 8176) : méthodes considérées comme une MFA ; acr : valeurs propres au fournisseur (OIDC_MFA_ACR).
    'mfa_amr' => ['mfa', 'otp', 'hwk', 'swk', 'sc', 'fpt', 'face', 'iris', 'retina', 'vbm'],
    'mfa_acr' => array_values(array_filter(array_map('trim', explode(',', (string) env('OIDC_MFA_ACR', ''))))),

    // Texte du bouton de la page de connexion
    'button_label' => (string) env('OIDC_BUTTON_LABEL', 'Sign in with SSO'),

    // Appels au fournisseur (découverte, jeton, clés), en secondes
    'timeout' => 10,

    // Découverte et clés (JWKS) gardées en cache ; une clé inconnue force une relecture des clés (rotation)
    'cache_seconds' => 3600,

    // Tolérance d'horloge pour exp / iat, en secondes
    'leeway' => 60,

    // Délai maximal entre le départ vers le fournisseur et le retour
    'max_login_seconds' => 600,
];
