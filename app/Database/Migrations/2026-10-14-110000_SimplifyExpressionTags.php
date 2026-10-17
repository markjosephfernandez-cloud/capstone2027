<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class SimplifyExpressionTags extends Migration
{
    private function label(string $value): string
    {
        return preg_replace('/^\s*Expression\s*:\s*/i', '', $value) ?? $value;
    }
    private function tags(mixed $value): mixed
    {
        if (is_string($value)) {
            return implode(', ', array_values(array_unique(array_map(fn ($tag) => $this->label(trim($tag)), explode(',', $value)))));
        }
        if (!is_array($value)) return $value;
        if (array_is_list($value)) {
            $clean = array_map(fn ($tag) => is_string($tag) ? $this->label($tag) : $tag, $value);
            return count(array_filter($clean,'is_string')) === count($clean) ? array_values(array_unique($clean)) : $clean;
        }
        foreach ($value as &$tags) $tags = $this->tags($tags);
        return $value;
    }
    private function metadata(array $data): array
    {
        $original = $data;
        foreach ($data as $key => &$value) {
            if ($key === 'tags') $value = $this->tags($value);
            elseif ($key === 'auto_tags' && is_array($value)) {
                foreach ($value as &$tag) if (is_array($tag) && isset($tag['label'])) $tag['label'] = $this->label($tag['label']);
                unset($tag);
            } elseif (is_array($value)) $value = $this->metadata($value);
        }
        unset($value);
        if ($data !== $original && isset($data['digital_signature'])) {
            unset($data['digital_signature']);
            $data['digital_signature'] = hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        return $data;
    }
    public function up()
    {
        $specs = [
            ['files','id','tags','tags'], ['department_files','id','tags','tags'],
            ['tag','tag_id','tag','label'], ['server_library_index','id','tags','json_tags'],
            ['server_metadata','source_key','payload','json'], ['server_media','id','metadata_json','json'],
            ['media_visual_analysis','file_id','result_json','json'],
        ];
        $this->db->transBegin();
        try {
            foreach ($specs as [$table,$id,$column,$kind]) {
                if (!$this->db->tableExists($table) || !$this->db->fieldExists($column,$table)) continue;
                $sql = $this->db->table($table)->select($id.', '.$column)->like($column,'Expression')->getCompiledSelect();
                if ($this->db->DBDriver !== 'SQLite3') $sql .= ' FOR UPDATE';
                $rows = $this->db->query($sql)->getResultArray();
                foreach ($rows as $row) {
                    $old = $row[$column];
                    if (str_starts_with($kind,'json')) {
                        $decoded = json_decode($old,true,512,JSON_THROW_ON_ERROR);
                        $clean = $kind === 'json_tags' ? $this->tags($decoded) : $this->metadata($decoded);
                        if ($clean === $decoded) continue;
                        $new = json_encode($clean,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    } else $new = $this->{$kind}($old);
                    if ($old === $new) continue;
                    if (strlen($new) > 16000 && $this->db->DBDriver === 'MySQLi') {
                        // Shared metadata can exceed MariaDB's packet limit. The row stays locked
                        // and the transaction exposes only the completed JSON to other readers.
                        $this->db->table($table)->where($id,$row[$id])->update([$column=>'']);
                        $t = $this->db->protectIdentifiers($table,true);
                        $c = $this->db->protectIdentifiers($column);
                        $pk = $this->db->protectIdentifiers($id);
                        foreach (mb_str_split($new, 4000, 'UTF-8') as $chunk) {
                            $this->db->query("UPDATE $t SET $c = CONCAT($c, ?) WHERE $pk = ?", [$chunk,$row[$id]]);
                        }
                    } else $this->db->table($table)->where($id,$row[$id])->update([$column=>$new]);
                }
            }
            if (!$this->db->transStatus()) throw new \RuntimeException('Expression tag cleanup failed.');
            $this->db->transCommit();
        } catch (\Throwable $error) { $this->db->transRollback(); throw $error; }
    }
    public function down() {} // Original prefixed and plain labels cannot be distinguished after normalization.
}
