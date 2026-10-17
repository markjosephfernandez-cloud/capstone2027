<?php

namespace App\Libraries;

use RuntimeException;

/** Copies already-authorized paths, with at most two child processes per request. */
class ParallelFileCopier
{
    public function copy(array $jobs, int $concurrency = 2, int $timeout = 180): void
    {
        $binary = in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? PHP_BINARY : PHP_BINDIR . '/php';
        $parallel = function_exists('proc_open') && is_executable($binary) && $concurrency > 1;
        $active = [];
        $next = 0;
        $deadline = microtime(true) + $timeout;
        try {
            while ($next < count($jobs) || $active !== []) {
                if (microtime(true) >= $deadline) throw new RuntimeException('Copying server files timed out.');
                while ($next < count($jobs) && count($active) < min(2, max(1, $concurrency))) {
                    [$source, $target] = $jobs[$next++];
                    if (!$parallel) {
                        if (!@copy($source, $target)) throw new RuntimeException('A server file could not be copied.');
                        break;
                    }
                    // Arguments bypass the shell, including spaces and punctuation in filenames.
                    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
                    $process = proc_open([$binary, '-n', '-r', 'exit(@copy($argv[1], $argv[2]) ? 0 : 1);', $source, $target],
                        [0=>['file', $null, 'r'], 1=>['file', $null, 'w'], 2=>['file', $null, 'w']], $pipes);
                    if (!is_resource($process)) throw new RuntimeException('A file copy worker could not start.');
                    $active[] = $process;
                }
                foreach ($active as $key => $process) {
                    $status = proc_get_status($process);
                    if ($status['running']) continue;
                    proc_close($process);
                    unset($active[$key]);
                    if ($status['exitcode'] !== 0) throw new RuntimeException('A server file could not be copied.');
                }
                if ($active !== []) usleep(10000);
            }
        } finally {
            // Stop writers before the caller removes partial destinations.
            foreach ($active as $process) {
                proc_terminate($process, 9);
                proc_close($process);
            }
        }
    }
}
