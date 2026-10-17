<?php
namespace App\Services;

use Config\Storage;
use RuntimeException;

/** Retry only the generated temporary paths recorded by the completed migration. */
final class CentralMigrationCleanup
{
    public function __construct(
        private readonly Storage $config = new Storage(),
        private readonly string $ledger = WRITEPATH.'cache/central-migration-cleanup.json',
    ) {}

    public function run(int $limit = 25): array
    {
        if (!is_file($this->ledger)) return ['removed'=>0,'remaining'=>0];
        $lock = fopen($this->ledger.'.lock','c');
        if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) { if ($lock) fclose($lock); return ['removed'=>0,'busy'=>true]; }
        try {
            $paths = json_decode((string)file_get_contents($this->ledger),true,512,JSON_THROW_ON_ERROR);
            if (!is_array($paths) || array_filter($paths,static fn ($path) => !is_string($path))) throw new RuntimeException('Invalid migration cleanup ledger.');
            if (!$paths) return ['removed'=>0,'remaining'=>0];
            $root = (new ServerLibraryService($this->config))->root();
            $remaining = array_slice($paths,$limit); $removed = 0; $blocked = 0;
            foreach (array_slice($paths,0,$limit) as $path) {
                $valid = preg_match('/^\.migration-[a-f0-9]{24}$/D',basename($path))
                    && (str_starts_with($path,$root.'/Imported media/') || str_starts_with($path,$root.'/DEPARTMENT/'));
                if ($valid) {
                    $cursor = $root;
                    foreach (explode('/',substr($path,strlen($root)+1)) as $part) {
                        $cursor .= '/'.$part;
                        if ($part === '' || $part === '.' || $part === '..' || is_link($cursor)) { $valid = false; break; }
                    }
                }
                if (!$valid) { $remaining[] = $path; $blocked++; continue; }
                if (!file_exists($path)) continue;
                if (is_file($path) && @unlink($path)) $removed++;
                else { $remaining[] = $path; $blocked++; }
            }
            $temporary = $this->ledger.'.new';
            if (file_put_contents($temporary,json_encode(array_values(array_unique($remaining)),JSON_THROW_ON_ERROR),LOCK_EX) === false
                || !rename($temporary,$this->ledger)) throw new RuntimeException('Could not save cleanup progress.');
            return ['removed'=>$removed,'remaining'=>count($remaining),'blocked'=>$blocked];
        } finally { flock($lock,LOCK_UN); fclose($lock); }
    }
}
