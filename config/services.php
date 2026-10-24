<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'zabbix' => [
        'url' => env('ZABBIX_URL', 'http://zabbix-web:8080/api_jsonrpc.php'),
        'token' => env('ZABBIX_API_TOKEN'),
        'timeout' => (int) env('ZABBIX_TIMEOUT', 15),
        'retries' => (int) env('ZABBIX_RETRIES', 2),
        'retry_delay' => (int) env('ZABBIX_RETRY_DELAY', 200),
    ],

    // Grafana (SCRUM-47) : url = adresse vue par le navigateur (liens) ; internal_url = vue par l'application (état)
    'grafana' => [
        'url' => env('GRAFANA_URL', 'http://localhost:8000/grafana'),
        'internal_url' => env('GRAFANA_INTERNAL_URL', 'http://grafana:3000/grafana'),
        'dashboard_uid' => env('GRAFANA_DASHBOARD_UID', 'monitoring-platform-target'),
        'timeout' => (int) env('GRAFANA_TIMEOUT', 2),
        // Accès par team (SCRUM-48) : API d'administration, côté serveur uniquement
        'admin_user' => env('GRAFANA_ADMIN_USER', 'admin'),
        'admin_password' => env('GRAFANA_ADMIN_PASSWORD'),
        // Datasource Zabbix des organisations des teams : API Zabbix vue depuis Grafana, rôle de l'utilisateur Zabbix
        'zabbix_api_url' => env('GRAFANA_ZABBIX_API_URL', 'http://zabbix-web:8080/api_jsonrpc.php'),
        'zabbix_role_id' => env('GRAFANA_ZABBIX_ROLE_ID', '1'),
        'dashboard_file' => base_path('docker/grafana/provisioning/dashboards/target-overview.json'),
    ],

];
