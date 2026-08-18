<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddVisualDuplicateDetection extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('files') && ! in_array('image_fingerprint', $this->db->getFieldNames('files'), true)) {
            $this->forge->addColumn('files', [
                'image_fingerprint' => ['type' => 'CHAR', 'constraint' => 16, 'null' => true, 'after' => 'content_hash'],
            ]);
        }
        if ($this->db->tableExists('duplicate_media')) {
            $fields = $this->db->getFieldNames('duplicate_media');
            $columns = [];
            if (! in_array('image_fingerprint', $fields, true)) {
                $columns['image_fingerprint'] = ['type' => 'CHAR', 'constraint' => 16, 'null' => true, 'after' => 'content_hash'];
            }
            if (! in_array('match_type', $fields, true)) {
                $columns['match_type'] = ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'exact', 'null' => false, 'after' => 'image_fingerprint'];
            }
            if (! in_array('similarity_score', $fields, true)) {
                $columns['similarity_score'] = ['type' => 'DECIMAL', 'constraint' => '5,2', 'default' => 100, 'null' => false, 'after' => 'match_type'];
            }
            if ($columns !== []) $this->forge->addColumn('duplicate_media', $columns);
        }
    }

    public function down()
    {
        if ($this->db->tableExists('duplicate_media')) {
            $fields = array_values(array_intersect(['image_fingerprint', 'match_type', 'similarity_score'], $this->db->getFieldNames('duplicate_media')));
            if ($fields !== []) $this->forge->dropColumn('duplicate_media', $fields);
        }
        if ($this->db->tableExists('files') && in_array('image_fingerprint', $this->db->getFieldNames('files'), true)) {
            $this->forge->dropColumn('files', 'image_fingerprint');
        }
    }
}
