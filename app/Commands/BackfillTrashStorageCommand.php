<?php

namespace App\Commands;

use App\Services\TrashService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class BackfillTrashStorageCommand extends BaseCommand
{
    protected $group = 'Greyhound';
    protected $name = 'trash:backfill-storage';
    protected $description = 'Moves existing Trash originals into uploads/Trashed while preserving restoration paths and deletion dates.';
    protected $options = ['--dry-run' => 'Inspect existing Trash without changing files or records.'];

    public function run(array $params)
    {
        $db = db_connect();
        $files = $db->table('files f')->select('f.*')->join('trashfiles tf', 'tf.file_id = f.id', 'inner')
            ->where('f.is_deleted', 1)->groupStart()
            ->where('tf.original_filepath IS NULL', null, false)
            ->orLike('f.filepath', 'writable/uploads/Trashed/', 'after')
            ->groupEnd()->get()->getResultArray();
        $dryRun = CLI::getOption('dry-run') !== null;
        $service = new TrashService($db);
        $moved = 0;
        $errors = 0;
        foreach ($files as $file) {
            try {
                $physical = service('storage')->resolveDatabaseFile($file['filepath']);
                $path = service('storage')->databasePath($physical);
                if (dirname($path) === 'writable/uploads/Trashed') {
                    continue;
                }
                if (! $dryRun) {
                    $service->relocateLegacyTrash($file);
                }
                ++$moved;
            } catch (\Throwable $exception) {
                ++$errors;
                CLI::write('File #' . $file['id'] . ': ' . $exception->getMessage(), 'yellow');
            }
        }
        CLI::write(($dryRun ? 'Available to move: ' : 'Moved into Trashed: ') . $moved);
        CLI::write('Unavailable items left unchanged: ' . $errors);
        return $errors === 0 ? EXIT_SUCCESS : EXIT_ERROR;
    }
}
