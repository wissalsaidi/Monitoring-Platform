<?php

/*
 * Configuration de production sécurisée (SCRUM-61) : voir docs/exploitation/production.md
 */

return [

    // Strict-Transport-Security, envoyé uniquement en HTTPS (hors localhost). 0 = désactivé.
    // Commencer par une valeur courte (300) à la mise en ligne, puis un an une fois HTTPS validé.
    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

    // 2FA obligatoire pour les Super Admin et les Admin de team (SCRUM-86). Ne la couper que pour une démo jetable.
    'two_factor_for_admins' => (bool) env('TWO_FACTOR_FOR_ADMINS', true),

];
