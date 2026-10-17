<?php

namespace App\Commands;

use App\Services\TrashService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class PurgeTrashCommand extends BaseCommand
{
    protected $group       = 'Greyhound';
    protected $name        = 'trash:purge';
    protected $description = 'Permanently removes Trash media that has been in Trash for 30 days.';
    protected $usage       = 'trash:purge [--limit 500]';
    protected $options     = [
        '--limit' => 'Maximum number of expired Trash items to purge in one run. Default: 500.',
    ];

    public function run(array $params)
    {
        $limit = filter_var(CLI::getOption('limit') ?? 500, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1) {
            $limit = 500;
        }

        $actor = [
            'employee_id' => 0,
            'name'        => 'Trash retention cleanup',
            'department'  => 'System',
            'role'        => 'system',
        ];

        $purged = (new TrashService())->purgeExpiredTrash($actor, null, $limit);

        try {
            $purged += (new \App\Services\ServerLibraryEditor(new \App\Services\ServerLibraryService()))->purgeExpiredTrash($limit);
        } catch (\Throwable $exception) {
            CLI::error('Server Trash cleanup unavailable: ' . $exception->getMessage());
        }

        CLI::write('Purged ' . $purged . ' Trash item' . ($purged === 1 ? '' : 's') . ' older than ' . TrashService::RETENTION_DAYS . ' days.', 'green');
    }
}
