<?php

return [

    /*
    | Inscription (SCRUM-82) : invite (par défaut, aucune page d'inscription), domain (adresses des domaines
    | ci-dessous seulement) ou open (démo publique). Dans tous les cas, le compte est créé sans team : un
    | Admin de team ou un Super Admin l'y ajoute.
    */
    'mode' => env('REGISTRATION_MODE', 'invite'),

    // Mode domain : domaines autorisés, séparés par des virgules (ex. company.com,company.fr)
    'domains' => env('REGISTRATION_DOMAINS', ''),

    // Contact affiché sur la page « Waiting for access » (ex. it-support@company.com), facultatif
    'contact' => env('REGISTRATION_CONTACT'),

];
