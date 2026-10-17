<?php
namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use App\Services\ServerLibraryIndexer;
use App\Services\ServerLibraryCatalog;

class ServerLibraryIndexCommand extends BaseCommand
{
    protected $group = 'Greyhound';
    protected $name = 'server-library:index';
    protected $description = 'Catalog NAS metadata in the local database without copying originals.';
    protected $options = ['--seconds'=>'Stop after this many seconds and save progress (default: complete the scan).', '--restart'=>'Restart a reconciliation pass, keeping existing catalog rows until completion.', '--status'=>'Show catalog status without reading the NAS.', '--refresh-folder'=>'Publish a named folder and its immediate contents to the catalog without scanning the whole share.'];

    public function run(array $params)
    {
        try {
            $folder = CLI::getOption('refresh-folder');
            if ($folder !== null) {
                if (!is_string($folder) || $folder === '') throw new \RuntimeException('Provide a named folder relative to the server root.');
                $library = new \App\Services\ServerLibraryService();
                $folder = $library->relative($folder);
                $directory = $library->resolve($folder, true);
                $metadata = (new \App\Services\ServerLibraryEditor($library))->state();
                $writer = new \App\Services\ServerLibraryCatalogWriter($library);
                $writer->sync([$folder], $metadata);
                $count = 0;
                $deadline = microtime(true) + 60;
                foreach (new \DirectoryIterator($directory) as $entry) {
                    if (microtime(true) >= $deadline) throw new \RuntimeException('Folder refresh reached its time limit; completed entries are saved.');
                    if ($entry->isDot() || $entry->isLink() || str_starts_with($entry->getFilename(), '.')) continue;
                    $path = $folder . '/' . $entry->getFilename();
                    try { $library->relative($path); } catch (\RuntimeException) { continue; }
                    if (!$entry->isDir() && !$entry->isFile()) continue;
                    $writer->sync([$path], $metadata);
                    $count++;
                }
                $visible = (new ServerLibraryCatalog())->folderCounts($folder);
                CLI::write('Refreshed ' . $folder . ': ' . $count . ' entries; catalog now contains ' . $visible['files'] . ' files and ' . $visible['folders'] . ' folders.');
                return EXIT_SUCCESS;
            }
            if (CLI::getOption('status') !== null) {
                CLI::write(json_encode((new ServerLibraryCatalog())->statusInfo(), JSON_PRETTY_PRINT));
                return EXIT_SUCCESS;
            }
            $seconds = CLI::getOption('seconds');
            if ($seconds !== null && (!ctype_digit((string)$seconds) || (int)$seconds < 1)) throw new \RuntimeException('--seconds must be a positive integer.');
            $result = (new ServerLibraryIndexer(db_connect(), new \Config\Storage()))->run((int)($seconds ?? 0), CLI::getOption('restart') !== null);
            CLI::write('Catalog ' . $result['status'] . '; indexed ' . $result['processed'] . ' entries in this run.');
            return EXIT_SUCCESS;
        } catch (\Throwable $error) { CLI::error($error->getMessage()); return EXIT_ERROR; }
    }
}
