<?php
namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CentralMigrationCommand extends BaseCommand
{
    protected $group = 'Greyhound';
    protected $name = 'server-library:migrate-storage';
    protected $description = 'Inventory or copy legacy storage into the server share, preserving originals and record IDs.';
    protected $options = ['--apply'=>'Copy and verify files, then update references. Default is dry-run.', '--exclude-missing-duplicates'=>'Leave missing duplicate-queue entries unchanged.'];
    public function run(array $params)
    {
        $lock = fopen(WRITEPATH.'cache/central-migration.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { CLI::error('Migration already running.'); return EXIT_ERROR; }
        try {
            $report = (new \App\Services\CentralMigrationService())->run(CLI::getOption('apply') !== null,
                static fn ($table,$id) => CLI::write('Verified '.$table.' #'.$id), CLI::getOption('exclude-missing-duplicates') !== null);
            CLI::write(json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
            return $report['missing'] || $report['conflicts'] ? EXIT_ERROR : EXIT_SUCCESS;
        } catch (\Throwable $error) { CLI::error($error->getMessage()); return EXIT_ERROR; }
        finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
