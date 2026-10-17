<?php

namespace App\Commands;

use App\Services\DepartmentMediaRecoveryService;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class RecoverDepartmentMediaCommand extends BaseCommand
{
    protected $group = 'Greyhound';
    protected $name = 'department:recover-media';
    protected $description = 'Rebuild missing metadata for images already in department storage; dry-run unless --apply is provided.';
    protected $options = ['--apply'=>'Insert recovery records and save a rollback manifest.'];

    public function run(array $params)
    {
        if (db_connect()->tableExists('department_files')) { CLI::error('The independent library is enabled. Use department:separate-library for legacy assignments.'); return EXIT_ERROR; }
        $root = config('Storage')->departmentRoot();
        if (!is_dir($root)) { CLI::error('Department storage is unavailable.'); return EXIT_ERROR; }
        $lock = fopen(WRITEPATH . 'department-recovery.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { CLI::error('Another recovery is running.'); return EXIT_ERROR; }
        try {
            $service = new DepartmentMediaRecoveryService(db_connect(), service('storage'), $root);
            $plan = $service->plan();
            $totals = [];
            foreach ($plan as $entry) $totals[$entry['department']][$entry['action']] = ($totals[$entry['department']][$entry['action']] ?? 0) + 1;
            CLI::write(json_encode($totals, JSON_PRETTY_PRINT));
            if (CLI::getOption('apply') !== null) {
                $directory = WRITEPATH . 'recovery';
                if (!is_dir($directory) && !mkdir($directory, 0700, true)) throw new \RuntimeException('Cannot create recovery manifest directory.');
                $manifest = $directory . '/department-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
                $created = $service->apply($plan, $manifest);
                CLI::write(json_encode(array_map('count', $created)));
                CLI::write('Recovery manifest: ' . $manifest);
            } else CLI::write('Dry run only. Use --apply to recover these records.');
            return EXIT_SUCCESS;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
