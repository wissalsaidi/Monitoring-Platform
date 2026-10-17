<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Supprime les entrées du journal d'audit de plus d'un an (exigence RGPD-3, SCRUM-60).
 * Planifiée chaque jour (routes/console.php).
 */
class AuditPurge extends Command
{
    protected $signature = 'audit:purge';

    protected $description = 'Delete audit log entries older than the retention period';

    public function handle(): int
    {
        $days = (int) config('audit.retention_days');
        $deleted = 0;

        // Par lots, pour ne pas verrouiller la table longtemps
        do {
            $batch = AuditLog::where('created_at', '<', now()->subDays($days))->limit(1000)->delete();
            $deleted += $batch;
        } while ($batch > 0);

        Log::info('Audit log purged', ['deleted' => $deleted, 'retention_days' => $days]);
        $this->info("Deleted {$deleted} audit log entries older than {$days} days.");

        return self::SUCCESS;
    }
}
