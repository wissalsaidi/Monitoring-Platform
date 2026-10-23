<?php

use App\Monitoring\Provisioners\ApiProvisioner;
use App\Monitoring\Provisioners\ContainerProvisioner;
use App\Monitoring\Provisioners\ServerProvisioner;
use App\Monitoring\Provisioners\WebsiteProvisioner;

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

    /*
    | Provisioner Zabbix de chaque type de target (SCRUM-66) : interface App\Monitoring\TargetProvisioner.
    | Un type absent de cette liste n'est pas proposé à la création. Nouveau type : écrire son provisioner
    | (règles de validation, checks et triggers tagués target_id / metric / level) et l'ajouter ici.
    */
    'provisioners' => [
        'api' => ApiProvisioner::class,
        'website' => WebsiteProvisioner::class,
        'server' => ServerProvisioner::class,
        'container' => ContainerProvisioner::class,
    ],

    /*
    | Template Zabbix lié aux serveurs (SCRUM-69) : collecte et graphiques. Ses propres triggers ne deviennent pas
    | des alertes de la plateforme (pas de tag "level"), les seuils viennent de la target.
    */
    'server_template' => env('ZABBIX_SERVER_TEMPLATE', 'Linux by Zabbix agent'),

    /*
    | Agent Zabbix 2 (SCRUM-67) : adresse vue depuis zabbix-server, utilisée par défaut pour l'interface agent
    | des hosts (conteneurs, bases de données…). En local : le service zabbix-agent2 du docker-compose.
    */
    'agent' => [
        'host' => env('ZABBIX_AGENT_HOST', 'zabbix-agent2'),
        'port' => (int) env('ZABBIX_AGENT_PORT', 10050),
    ],

];
