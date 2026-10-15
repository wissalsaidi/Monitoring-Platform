<?php

return [

    /*
    | Hôtes internes autorisés comme URL de target malgré la protection SSRF (SCRUM-57).
    | À réserver aux services de démonstration (ex. la fake API du docker-compose).
    | Liste séparée par des virgules dans TARGET_ALLOWED_INTERNAL_HOSTS.
    */
    'allowed_internal_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TARGET_ALLOWED_INTERNAL_HOSTS', 'fake-api')),
    ))),

];
