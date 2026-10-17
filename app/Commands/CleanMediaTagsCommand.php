<?php

namespace App\Commands;

use App\Libraries\MediaTagPolicy;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

class CleanMediaTagsCommand extends BaseCommand
{
    protected $group = 'Greyhound';
    protected $name = 'media:clean-tags';
    protected $description = 'Simplify year/folder tags and remove retired generated tags; defaults to dry run.';
    protected $options = ['--apply' => 'Apply cleanup with a transaction and a JSONL backup.'];

    public function run(array $params)
    {
        $apply = (bool) CLI::getOption('apply');
        $db = db_connect();
        $filesChanged = 0;
        $tagsRemoved = 0;
        $tagsRenamed = 0;
        $backup = null;
        if ($apply) {
            $directory = WRITEPATH . 'backups';
            if (! is_dir($directory)) mkdir($directory, 0700, true);
            $path = $directory . '/tag-cleanup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.jsonl';
            $backup = fopen($path, 'xb');
            if (! $backup) throw new \RuntimeException('Could not create tag backup.');
            chmod($path, 0600);
            $db->transBegin();
        }
        $record = static function (array $row) use ($backup): void {
            $line = json_encode($row, JSON_THROW_ON_ERROR) . "\n";
            if (fwrite($backup, $line) !== strlen($line) || ! fflush($backup)) throw new \RuntimeException('Tag backup could not be written.');
        };
        try {
            foreach (['files', 'department_files'] as $table) {
                if (! $db->tableExists($table)) continue;
                $lastId = 0;
                do {
                    $rows = $db->table($table)->select('id, tags, filename, filetype, mime_type, media_kind')->where('id >', $lastId)->orderBy('id')->limit(500)->get()->getResultArray();
                    foreach ($rows as $file) {
                        $lastId = (int) $file['id'];
                        $old = (string) ($file['tags'] ?? '');
                        $clean = MediaTagPolicy::clean($old, $file);
                        if ($clean === $old) continue;
                        $filesChanged++;
                        if ($apply) {
                            $record(['table' => $table, 'id' => $lastId, 'tags' => $old]);
                            $db->table($table)->where('id', $lastId)->where('tags', $old)->update(['tags' => $clean]);
                            if ($db->affectedRows() !== 1) throw new \RuntimeException('A file changed during cleanup; retry safely.');
                        }
                    }
                } while (count($rows) === 500);
            }
            if ($db->tableExists('tag')) {
                $lastId = 0;
                do {
                    $rows = $db->table('tag t')->select('t.*, f.filename, f.filetype, f.mime_type, f.media_kind')->join('files f', 'f.id=t.file_id', 'left')->where('t.tag_id >', $lastId)->orderBy('t.tag_id')->limit(500)->get()->getResultArray();
                    foreach ($rows as $row) {
                        $lastId = (int) $row['tag_id'];
                        $clean = MediaTagPolicy::normalize((string) $row['tag'], $row);
                        if ($clean === (string) $row['tag']) continue;
                        $clean === '' ? $tagsRemoved++ : $tagsRenamed++;
                        if ($apply) {
                            $original = array_diff_key($row, array_flip(['filename', 'filetype', 'mime_type', 'media_kind']));
                            $record(['table' => 'tag', 'row' => $original]);
                            $builder = $db->table('tag')->where('tag_id', $lastId)->where('tag', $row['tag']);
                            $clean === '' ? $builder->delete() : $builder->update(['tag' => $clean]);
                            if ($db->affectedRows() !== 1) throw new \RuntimeException('A tag changed during cleanup; retry safely.');
                        }
                    }
                } while (count($rows) === 500);
            }
            if ($apply) {
                if (! $db->transStatus()) throw new \RuntimeException('Database cleanup failed.');
                $db->transCommit();
                fclose($backup);
                CLI::write('Backup: ' . $path);
            }
        } catch (\Throwable $error) {
            if ($apply) { $db->transRollback(); if (is_resource($backup)) fclose($backup); }
            throw $error;
        }
        CLI::write(($apply ? 'Updated ' : 'Would update ') . $filesChanged . ' file records; ' . ($apply ? 'removed ' : 'would remove ') . $tagsRemoved . ' tag records; ' . ($apply ? 'renamed ' : 'would rename ') . $tagsRenamed . ' tag records.');
        return EXIT_SUCCESS;
    }
}
