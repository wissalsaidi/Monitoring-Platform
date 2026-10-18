<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Sauvegarde des bases Laravel et Zabbix, puis suppression des sauvegardes trop anciennes (SCRUM-62).
 * Planifiée chaque nuit (routes/console.php).
 */
class BackupRun extends Command
{
    protected $signature = 'backup:run {database?* : laravel, zabbix (toutes par défaut)}';

    protected $description = 'Back up the MySQL databases and delete old backups';

    public function handle(BackupService $backups): int
    {
        $databases = $this->argument('database') ?: $backups->databases();
        $failed = false;

        foreach ($databases as $database) {
            try {
                $info = $backups->backup($database);
                $this->info(sprintf('%s: %s (%s KB, %s s)', $database, basename($info['file']), number_format($info['bytes'] / 1024), $info['seconds']));
            } catch (RuntimeException $e) {
                $this->error($e->getMessage());
                $failed = true;
            }
        }

        foreach ($backups->prune() as $file) {
            $this->line("Deleted old backup {$file}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
