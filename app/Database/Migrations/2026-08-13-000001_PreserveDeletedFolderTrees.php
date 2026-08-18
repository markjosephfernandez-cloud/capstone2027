<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class PreserveDeletedFolderTrees extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('folder')) {
            $fields = $this->db->getFieldNames('folder');
            if (! in_array('is_deleted', $fields, true)) {
                $this->forge->addColumn('folder', [
                    'is_deleted' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'after' => 'published_by'],
                ]);
            }
            $fields = $this->db->getFieldNames('folder');
            if (! in_array('deleted_at', $fields, true)) {
                $this->forge->addColumn('folder', [
                    'deleted_at' => ['type' => 'DATETIME', 'null' => true, 'after' => 'is_deleted'],
                ]);
            }
            $fields = $this->db->getFieldNames('folder');
            if (! in_array('deleted_by', $fields, true)) {
                $this->forge->addColumn('folder', [
                    'deleted_by' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'deleted_at'],
                ]);
            }
            $fields = $this->db->getFieldNames('folder');
            if (! in_array('deletion_reason', $fields, true)) {
                $this->forge->addColumn('folder', [
                    'deletion_reason' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true, 'after' => 'deleted_by'],
                ]);
            }
            $fields = $this->db->getFieldNames('folder');
            if (! in_array('trash_root_id', $fields, true)) {
                $this->forge->addColumn('folder', [
                    'trash_root_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'deletion_reason'],
                ]);
                $this->db->query('CREATE INDEX `idx_folder_trash_root` ON `folder` (`is_deleted`, `trash_root_id`)');
            }
        }

        if ($this->db->tableExists('trashfiles') && ! in_array('folder_root_id', $this->db->getFieldNames('trashfiles'), true)) {
            $this->forge->addColumn('trashfiles', [
                'folder_root_id' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'file_id'],
            ]);
            $this->db->query('CREATE INDEX `idx_trashfiles_folder_root` ON `trashfiles` (`folder_root_id`, `deleted_at`)');
        }

        $this->recoverLegacyDeletedFolders();
    }

    public function down()
    {
        if ($this->db->tableExists('folder') && in_array('is_deleted', $this->db->getFieldNames('folder'), true)) {
            $deletedFolderIds = array_map('intval', array_column($this->db->table('folder')->select('id')->where('is_deleted', 1)->get()->getResultArray(), 'id'));
            if ($deletedFolderIds !== [] && $this->db->tableExists('files')) {
                $this->db->table('files')->whereIn('folder_id', $deletedFolderIds)->update(['folder_id' => null]);
                $this->db->table('folder')->whereIn('id', $deletedFolderIds)->delete();
            }
        }
        if ($this->db->tableExists('trashfiles') && in_array('folder_root_id', $this->db->getFieldNames('trashfiles'), true)) {
            $this->forge->dropColumn('trashfiles', 'folder_root_id');
        }
        if ($this->db->tableExists('folder')) {
            foreach (['trash_root_id', 'deletion_reason', 'deleted_by', 'deleted_at', 'is_deleted'] as $field) {
                if (in_array($field, $this->db->getFieldNames('folder'), true)) {
                    $this->forge->dropColumn('folder', $field);
                }
            }
        }
    }

    private function recoverLegacyDeletedFolders(): void
    {
        if (! $this->db->tableExists('folder') || ! $this->db->tableExists('trashfiles') || ! $this->db->tableExists('files')) {
            return;
        }

        $rows = $this->db->table('trashfiles tf')
            ->select('tf.id, tf.file_id, tf.deleted_at, tf.deleted_by, tf.reason')
            ->join('files f', 'f.id = tf.file_id', 'inner')
            ->where('f.is_deleted', 1)
            ->where('f.folder_id IS NULL', null, false)
            ->where('tf.folder_root_id IS NULL', null, false)
            ->like('tf.reason', 'Moved to Trash when folder "', 'after')
            ->orderBy('tf.deleted_at', 'ASC')
            ->get()
            ->getResultArray();

        $groups = [];
        foreach ($rows as $row) {
            if (preg_match('/^Moved to Trash when folder "(.+)" was deleted$/u', (string) $row['reason'], $matches) !== 1) {
                continue;
            }
            $deletedAt = (string) ($row['deleted_at'] ?? '');
            $key = (string) $row['reason'] . '|' . (string) ($row['deleted_by'] ?? '') . '|' . substr($deletedAt, 0, 19);
            $groups[$key]['name'] = mb_substr($matches[1], 0, 120);
            $groups[$key]['deleted_at'] = $deletedAt !== '' ? $deletedAt : gmdate('Y-m-d H:i:s');
            $groups[$key]['deleted_by'] = $row['deleted_by'];
            $groups[$key]['trash_ids'][] = (int) $row['id'];
            $groups[$key]['file_ids'][] = (int) $row['file_id'];
        }

        foreach ($groups as $group) {
            $this->db->table('folder')->insert([
                'parent_id' => null,
                'name' => $group['name'],
                'description' => 'Recovered from a folder deleted before folder-aware Trash was enabled.',
                'path' => null,
                'is_deleted' => 1,
                'deleted_at' => $group['deleted_at'],
                'deleted_by' => $group['deleted_by'],
                'deletion_reason' => 'Deleted folder recovered from legacy Trash records',
            ]);
            $folderId = (int) $this->db->insertID();
            $this->db->table('folder')->where('id', $folderId)->update(['trash_root_id' => $folderId]);
            $this->db->table('trashfiles')->whereIn('id', $group['trash_ids'])->update(['folder_root_id' => $folderId]);
            $this->db->table('files')->whereIn('id', $group['file_ids'])->update(['folder_id' => $folderId]);
        }
    }
}
