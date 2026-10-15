<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class DatabaseBackupTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/backups-'.uniqid());
        config([
            'backup.path' => $this->directory,
            'backup.databases.laravel' => ['host' => 'mysql', 'port' => 3306, 'database' => 'monitoring', 'username' => 'monitoring', 'password' => 's3cret-laravel'],
            'backup.databases.zabbix' => ['host' => 'zabbix-db', 'port' => 3306, 'database' => 'zabbix', 'username' => 'zabbix', 'password' => 's3cret-zabbix'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** Faux mariadb-dump : écrit le fichier .partial attendu par le service, comme le ferait gzip */
    private function fakeSuccessfulDumps(): void
    {
        Process::fake(function (PendingProcess $process) {
            if (preg_match("/> '([^']+\\.partial)'/", $process->command[2], $m)) {
                File::put($m[1], 'compressed dump');
            }

            return Process::result();
        });
    }

    public function test_it_backs_up_both_databases_into_compressed_files(): void
    {
        $this->fakeSuccessfulDumps();

        $this->artisan('backup:run')->assertSuccessful();

        $files = array_map('basename', File::glob($this->directory.'/*.sql.gz'));
        $this->assertCount(2, $files);
        $this->assertMatchesRegularExpression('/^laravel-\d{8}-\d{6}\.sql\.gz$/', $files[0]);
        $this->assertMatchesRegularExpression('/^zabbix-\d{8}-\d{6}\.sql\.gz$/', $files[1]);
        $this->assertSame([], File::glob($this->directory.'/*.partial'));
    }

    public function test_the_dump_is_consistent_and_targets_the_right_database(): void
    {
        $this->fakeSuccessfulDumps();

        $this->artisan('backup:run', ['database' => ['zabbix']])->assertSuccessful();

        Process::assertRan(function (PendingProcess $process) {
            $command = $process->command[2];

            return str_contains($command, 'set -o pipefail; mariadb-dump --single-transaction')
                && str_contains($command, "-h 'zabbix-db' -P 3306 -u 'zabbix' 'zabbix' | sed -E") && str_contains($command, 'DEFINER');
        });
    }

    public function test_the_password_never_appears_in_the_command_line(): void
    {
        $this->fakeSuccessfulDumps();

        $this->artisan('backup:run')->assertSuccessful();

        Process::assertRan(fn (PendingProcess $p) => $p->environment === ['MYSQL_PWD' => 's3cret-laravel'] && ! str_contains($p->command[2], 's3cret'));
        Process::assertRan(fn (PendingProcess $p) => $p->environment === ['MYSQL_PWD' => 's3cret-zabbix'] && ! str_contains($p->command[2], 's3cret'));
    }

    public function test_a_failed_dump_is_logged_as_critical_and_leaves_no_file(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'Access denied for user', exitCode: 2)]);
        $log = \Mockery::spy(LoggerInterface::class);
        Log::shouldReceive('channel')->with('backup')->andReturn($log);

        $this->artisan('backup:run', ['database' => ['laravel']])
            ->expectsOutputToContain('Backup of laravel failed: Access denied for user')
            ->assertFailed();

        $this->assertSame([], File::glob($this->directory.'/*'));
        $log->shouldHaveReceived('critical')->with('Database backup failed', ['database' => 'laravel', 'error' => 'Access denied for user'])->once();
    }

    public function test_an_empty_dump_counts_as_a_failure(): void
    {
        Process::fake(); // la commande « réussit » mais n'écrit rien

        $this->artisan('backup:run', ['database' => ['laravel']])->assertFailed();

        $this->assertSame([], File::glob($this->directory.'/*.sql.gz'));
    }

    public function test_backups_older_than_the_retention_are_deleted(): void
    {
        $this->fakeSuccessfulDumps();
        File::ensureDirectoryExists($this->directory);
        File::put($old = $this->directory.'/laravel-20261001-023000.sql.gz', 'old');
        File::put($recent = $this->directory.'/laravel-20261012-023000.sql.gz', 'recent');
        touch($old, now()->subDays(8)->timestamp);
        touch($recent, now()->subDays(3)->timestamp);

        $this->artisan('backup:run')->expectsOutput('Deleted old backup laravel-20261001-023000.sql.gz')->assertSuccessful();

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($recent);
    }

    public function test_the_backup_is_scheduled_every_night(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'backup:run'));

        $this->assertNotNull($event);
        $this->assertSame('30 2 * * *', $event->expression);
    }

    public function test_backups_are_listed_newest_first(): void
    {
        File::ensureDirectoryExists($this->directory);
        File::put($a = $this->directory.'/zabbix-20261014-023000.sql.gz', str_repeat('x', 2048));
        File::put($b = $this->directory.'/laravel-20261015-023000.sql.gz', 'x');
        touch($a, now()->subDay()->timestamp);

        $this->artisan('backup:list')
            ->expectsTable(['File', 'Database', 'Size', 'Date'], [
                ['laravel-20261015-023000.sql.gz', 'laravel', '0 KB', now()->toDisplay()->format('d/m/Y H:i')],
                ['zabbix-20261014-023000.sql.gz', 'zabbix', '2 KB', now()->subDay()->toDisplay()->format('d/m/Y H:i')],
            ])
            ->assertSuccessful();
    }

    public function test_restore_requires_force(): void
    {
        Process::fake();

        $this->artisan('backup:restore', ['database' => 'laravel', 'file' => 'laravel-x.sql.gz'])
            ->expectsOutputToContain('Run again with --force to confirm.')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_restore_pipes_the_backup_into_the_database(): void
    {
        Process::fake();
        File::ensureDirectoryExists($this->directory);
        File::put($this->directory.'/laravel-20261015-023000.sql.gz', 'dump');

        $this->artisan('backup:restore', ['database' => 'laravel', 'file' => 'laravel-20261015-023000.sql.gz', '--into' => 'monitoring_restore_test', '--force' => true])
            ->expectsOutput('Restored laravel-20261015-023000.sql.gz into monitoring_restore_test.')
            ->assertSuccessful();

        Process::assertRan(fn (PendingProcess $p) => str_contains($p->command[2], "gunzip -c '{$this->directory}/laravel-20261015-023000.sql.gz' | mariadb --skip-ssl -h 'mysql' -P 3306 -u 'monitoring' 'monitoring_restore_test'")
            && $p->environment === ['MYSQL_PWD' => 's3cret-laravel']);
    }

    public function test_restoring_a_missing_file_fails_cleanly(): void
    {
        Process::fake();

        $this->artisan('backup:restore', ['database' => 'laravel', 'file' => 'nope.sql.gz', '--force' => true])
            ->expectsOutput('Backup file not found: nope.sql.gz')
            ->assertFailed();

        Process::assertNothingRan();
    }
}
