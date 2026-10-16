<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Restauration d'une sauvegarde (SCRUM-62). Écrase les données : il faut --force.
 */
class BackupRestore extends Command
{
    protected $signature = 'backup:restore
        {database : laravel ou zabbix}
        {file : nom du fichier (voir backup:list)}
        {--into= : restaurer dans une autre base, pour tester une sauvegarde sans toucher aux données}
        {--force : confirmer l\'écrasement des données}';

    protected $description = 'Restore a database backup (overwrites the data)';

    public function handle(BackupService $backups): int
    {
        $target = $this->option('into') ?: $this->argument('database');

        if (! $this->option('force')) {
            $this->error("This overwrites the data of the {$target} database. Run again with --force to confirm.");

            return self::FAILURE;
        }

        try {
            $backups->restore($this->argument('database'), $this->argument('file'), $this->option('into'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Restored {$this->argument('file')} into {$target}.");

        return self::SUCCESS;
    }
}
