<?php

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/** Reprocesses older image passports with the local Greyhound Hub 2.0 model. */
class ReanalyzeMediaCommand extends BaseCommand
{
    protected $group       = 'Greyhound';
    protected $name        = 'ai:reanalyze-media';
    protected $description = 'Adds AI confidence, event tags, and passport data to older images without AI results.';
    protected $usage       = 'ai:reanalyze-media [limit]';
    protected $arguments   = [
        'limit' => 'Number of images to analyze in this batch (1 to 50, default 20).',
    ];

    public function run(array $params)
    {
        $limit = isset($params[0]) && ctype_digit((string) $params[0]) ? (int) $params[0] : 20;
        $actor = [
            'employee_id' => 0,
            'name'        => 'Greyhound Hub AI maintenance',
            'department'  => 'Creative Department',
            'role'        => 'admin',
        ];

        CLI::write('Analyzing up to ' . max(1, min(50, $limit)) . ' existing images...', 'yellow');
        $result = service('passports')->reanalyzeMissingAi($actor, $limit);
        CLI::write($result['classified'] . ' image(s) classified; ' . $result['unavailable'] . ' image(s) still need analysis.', $result['unavailable'] === 0 ? 'green' : 'yellow');

        return EXIT_SUCCESS;
    }
}
