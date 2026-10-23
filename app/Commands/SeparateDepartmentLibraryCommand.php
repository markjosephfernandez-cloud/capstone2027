<?php
namespace App\Commands;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Services\DepartmentLibraryService;

class SeparateDepartmentLibraryCommand extends BaseCommand
{
    protected $group='Greyhound';
    protected $name='department:separate-library';
    protected $description='Snapshot completed department deliveries into independent records and detach shared physical copies.';
    public function run(array $params)
    {
        $db=db_connect();
        if (!$db->tableExists('department_files') || !$db->tableExists('department_folders')) {
            CLI::error('Run the independent department library migration first.'); return EXIT_ERROR;
        }
        $lock=fopen(WRITEPATH.'department-library.lock','c');
        if (! $lock || ! flock($lock, LOCK_EX | LOCK_NB)) {
            CLI::error('Another library migration is running.');
            return EXIT_ERROR;
        }
        try {
            $result=(new DepartmentLibraryService($db,service('storage')))->backfill();
            $directory=WRITEPATH.'recovery';
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            $report=$directory.'/independent-departments-'.gmdate('Ymd-His').'.json';
            file_put_contents($report,json_encode(['result'=>$result,'files'=>$db->table('department_files')->get()->getResultArray(),'folders'=>$db->table('department_folders')->get()->getResultArray()],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),LOCK_EX);
            CLI::write(json_encode($result,JSON_PRETTY_PRINT));
            CLI::write('Snapshot report: '.$report);
            return EXIT_SUCCESS;
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
