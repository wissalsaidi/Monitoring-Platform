<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupList extends Command
{
    protected $signature = 'backup:list';

    protected $description = 'List the available database backups';

    public function handle(BackupService $backups): int
    {
        $rows = array_map(fn ($b) => [$b['file'], $b['database'], number_format($b['bytes'] / 1024).' KB', $b['date']->toDisplay()->format('d/m/Y H:i')], $backups->list());

        $rows === [] ? $this->line('No backup yet.') : $this->table(['File', 'Database', 'Size', 'Date'], $rows);

        return self::SUCCESS;
    }
}
