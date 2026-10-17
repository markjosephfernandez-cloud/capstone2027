<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Creates designated department folders and copies media from older assignments. */
class BackfillDepartmentStorageCommand extends BaseCommand
{
    protected $group = 'Greyhound';
    protected $name = 'department:backfill-storage';
    protected $description = 'Creates writable/Department folders and copies media for existing department assignments.';

    public function run(array $params)
    {
        CLI::write('Creating department folders and copying existing assigned media...', 'yellow');
        $result = service('distribution')->backfillDepartmentStorage();
        CLI::write('Department folders ensured: ' . $result['departments'], 'green');
        CLI::write('Physical files copied: ' . $result['files_copied'], 'green');
        CLI::write('Assignment paths updated: ' . $result['assignments_updated'], 'green');
        CLI::write('Folder paths updated: ' . $result['folders_updated'], 'green');
        CLI::write('Skipped: ' . $result['skipped'], $result['skipped'] === 0 ? 'green' : 'yellow');
        foreach (array_slice($result['errors'], 0, 20) as $error) {
            CLI::write($error, 'red');
        }
        if (count($result['errors']) > 20) {
            CLI::write((count($result['errors']) - 20) . ' additional error(s) were omitted.', 'red');
        }

        return $result['errors'] === [] ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
