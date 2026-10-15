<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Sauvegarde et restauration des bases MySQL (SCRUM-62).
 * Le mot de passe passe par la variable d'environnement MYSQL_PWD : il n'apparaît jamais
 * dans la ligne de commande (visible par `ps`) ni dans les logs.
 */
class BackupService
{
    /** Options du dump : cohérent sans verrouiller les tables (InnoDB), sans dépendre des droits PROCESS */
    private const DUMP_OPTIONS = '--single-transaction --quick --routines --triggers --no-tablespaces --skip-ssl';

    /** Supprime les commentaires « /*!50017 DEFINER=`root`@`%`*\/ » des triggers, vues et routines */
    private const STRIP_DEFINER = 's#/\*!500[0-9]{2} DEFINER=[^*]*\*/##g';

    /**
     * Sauvegarde une base dans un fichier compressé.
     *
     * @return array{database: string, file: string, bytes: int, seconds: float}
     *
     * @throws RuntimeException
     */
    public function backup(string $name): array
    {
        $db = $this->database($name);
        $directory = $this->directory();
        $file = sprintf('%s/%s-%s.sql.gz', $directory, $name, now()->format('Ymd-His'));
        $partial = $file.'.partial';
        $started = microtime(true);

        // pipefail : un échec de mariadb-dump fait échouer la commande, même si gzip réussit.
        // sed retire les DEFINER=root des triggers (Zabbix) : sinon seul root pourrait les restaurer.
        $command = sprintf(
            'set -o pipefail; mariadb-dump %s -h %s -P %d -u %s %s | sed -E %s | gzip > %s',
            self::DUMP_OPTIONS,
            escapeshellarg($db['host']),
            (int) $db['port'],
            escapeshellarg($db['username']),
            escapeshellarg($db['database']),
            escapeshellarg(self::STRIP_DEFINER),
            escapeshellarg($partial),
        );

        $result = Process::env(['MYSQL_PWD' => (string) $db['password']])
            ->timeout(1800)
            ->run(['sh', '-c', $command]);

        if ($result->failed() || ! File::exists($partial) || File::size($partial) === 0) {
            File::delete($partial);
            $error = trim($result->errorOutput()) ?: 'empty dump';
            Log::channel('backup')->critical('Database backup failed', ['database' => $name, 'error' => $error]);

            throw new RuntimeException("Backup of {$name} failed: {$error}");
        }

        File::move($partial, $file);
        $info = ['database' => $name, 'file' => $file, 'bytes' => File::size($file), 'seconds' => round(microtime(true) - $started, 1)];
        Log::channel('backup')->info('Database backup done', $info);

        return $info;
    }

    /**
     * Supprime les sauvegardes plus anciennes que la durée de conservation.
     *
     * @return list<string> fichiers supprimés
     */
    public function prune(): array
    {
        $limit = now()->subDays(config('backup.retention_days'))->timestamp;
        $deleted = [];

        foreach (File::glob($this->directory().'/*.sql.gz') as $file) {
            if (File::lastModified($file) < $limit) {
                File::delete($file);
                $deleted[] = basename($file);
            }
        }

        if ($deleted !== []) {
            Log::channel('backup')->info('Old backups deleted', ['files' => $deleted]);
        }

        return $deleted;
    }

    /**
     * Sauvegardes disponibles, les plus récentes d'abord.
     *
     * @return list<array{file: string, database: string, bytes: int, date: Carbon}>
     */
    public function list(): array
    {
        $files = array_map(fn (string $file) => [
            'file' => basename($file),
            'database' => explode('-', basename($file))[0],
            'bytes' => File::size($file),
            'date' => Carbon::createFromTimestamp(File::lastModified($file)),
        ], File::glob($this->directory().'/*.sql.gz'));

        usort($files, fn ($a, $b) => $b['date'] <=> $a['date']);

        return $files;
    }

    /**
     * Restaure une sauvegarde dans une base (par défaut la base d'origine).
     *
     * @throws RuntimeException
     */
    public function restore(string $name, string $file, ?string $intoDatabase = null): void
    {
        $db = $this->database($name);
        $path = str_contains($file, '/') ? $file : $this->directory().'/'.$file;

        if (! File::exists($path)) {
            throw new RuntimeException("Backup file not found: {$file}");
        }

        $command = sprintf(
            'set -o pipefail; gunzip -c %s | mariadb --skip-ssl -h %s -P %d -u %s %s',
            escapeshellarg($path),
            escapeshellarg($db['host']),
            (int) $db['port'],
            escapeshellarg($db['username']),
            escapeshellarg($intoDatabase ?? $db['database']),
        );

        $result = Process::env(['MYSQL_PWD' => (string) $db['password']])
            ->timeout(1800)
            ->run(['sh', '-c', $command]);

        if ($result->failed()) {
            Log::channel('backup')->critical('Database restore failed', ['database' => $name, 'file' => basename($path), 'error' => trim($result->errorOutput())]);

            throw new RuntimeException("Restore of {$name} failed: ".trim($result->errorOutput()));
        }

        Log::channel('backup')->warning('Database restored', ['database' => $name, 'into' => $intoDatabase ?? $db['database'], 'file' => basename($path)]);
    }

    /** @return list<string> */
    public function databases(): array
    {
        return array_keys(config('backup.databases'));
    }

    private function database(string $name): array
    {
        return config("backup.databases.$name") ?? throw new RuntimeException("Unknown database: {$name}");
    }

    private function directory(): string
    {
        $directory = config('backup.path');
        File::ensureDirectoryExists($directory, 0750);

        return $directory;
    }
}
