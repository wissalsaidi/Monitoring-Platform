<?php

/*
 * Configuration de production sécurisée (SCRUM-61) : voir docs/exploitation/production.md
 */

return [

    // Strict-Transport-Security, envoyé uniquement en HTTPS (hors localhost). 0 = désactivé.
    // Commencer par une valeur courte (300) à la mise en ligne, puis un an une fois HTTPS validé.
    'hsts_max_age' => (int) env('SECURITY_HSTS_MAX_AGE', 31536000),

];
