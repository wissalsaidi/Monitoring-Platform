<?php

/*
| Sauvegarde des bases MySQL (SCRUM-62) : php artisan backup:run, planifiée chaque nuit.
| Les fichiers .sql.gz sont écrits hors des conteneurs MySQL, dans storage/app/backups (ignoré par Git).
*/
return [

    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 7),

    // Heure de la sauvegarde quotidienne (fuseau de l'application, UTC)
    'time' => env('BACKUP_TIME', '02:30'),

    'databases' => [
        'laravel' => [
            'host' => env('DB_HOST', 'mysql'),
            'port' => env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'monitoring'),
            'username' => env('DB_USERNAME', 'monitoring'),
            'password' => env('DB_PASSWORD', ''),
        ],
        'zabbix' => [
            'host' => env('ZBX_DB_HOST', 'zabbix-db'),
            'port' => env('ZBX_DB_PORT', 3306),
            'database' => env('ZBX_DB_DATABASE', 'zabbix'),
            'username' => env('ZBX_DB_USERNAME', 'zabbix'),
            'password' => env('ZBX_DB_PASSWORD', ''),
        ],
    ],

];
