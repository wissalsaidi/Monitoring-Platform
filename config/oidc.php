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
