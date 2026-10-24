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
        'url' => env('GRAFANA_URL', 'http://localhost:3000'),
        'internal_url' => env('GRAFANA_INTERNAL_URL', 'http://grafana:3000'),
        'dashboard_uid' => env('GRAFANA_DASHBOARD_UID', 'monitoring-platform-target'),
        'timeout' => (int) env('GRAFANA_TIMEOUT', 2),
    ],

];
