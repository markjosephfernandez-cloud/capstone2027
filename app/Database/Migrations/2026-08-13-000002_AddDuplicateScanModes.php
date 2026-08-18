<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDuplicateScanModes extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('files') && ! in_array('upload_batch_id', $this->db->getFieldNames('files'), true)) {
            $this->forge->addColumn('files', [
                'upload_batch_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'image_fingerprint'],
            ]);
            $this->db->query('CREATE INDEX `idx_files_upload_batch` ON `files` (`upload_batch_id`)');
        }

        if ($this->db->tableExists('duplicate_media')) {
            $fields = $this->db->getFieldNames('duplicate_media');
            $columns = [];
            if (! in_array('upload_batch_id', $fields, true)) {
                $columns['upload_batch_id'] = ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true, 'after' => 'similarity_score'];
            }
            if (! in_array('detection_scope', $fields, true)) {
                $columns['detection_scope'] = ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'media_library', 'null' => false, 'after' => 'upload_batch_id'];
            }
            if ($columns !== []) {
                $this->forge->addColumn('duplicate_media', $columns);
            }
        }
    }

    public function down()
    {
        if ($this->db->tableExists('duplicate_media')) {
            $fields = array_values(array_intersect(['detection_scope', 'upload_batch_id'], $this->db->getFieldNames('duplicate_media')));
            if ($fields !== []) {
                $this->forge->dropColumn('duplicate_media', $fields);
            }
        }

        if ($this->db->tableExists('files') && in_array('upload_batch_id', $this->db->getFieldNames('files'), true)) {
            $this->forge->dropColumn('files', 'upload_batch_id');
        }
    }
}
