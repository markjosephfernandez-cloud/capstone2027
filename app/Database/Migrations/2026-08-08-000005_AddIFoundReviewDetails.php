<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddIFoundReviewDetails extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('ai_event_detections')) {
            return;
        }

        $fields = $this->db->getFieldNames('ai_event_detections');
        $add = [];
        if (! in_array('reviewed_by', $fields, true)) {
            $add['reviewed_by'] = ['type' => 'INT', 'constraint' => 11, 'null' => true];
        }
        if (! in_array('reviewed_at', $fields, true)) {
            $add['reviewed_at'] = ['type' => 'DATETIME', 'null' => true];
        }
        if (! in_array('review_note', $fields, true)) {
            $add['review_note'] = ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true];
        }
        if ($add !== []) {
            $this->forge->addColumn('ai_event_detections', $add);
        }
    }

    public function down()
    {
        if (! $this->db->tableExists('ai_event_detections')) {
            return;
        }

        $fields = $this->db->getFieldNames('ai_event_detections');
        foreach (['review_note', 'reviewed_at', 'reviewed_by'] as $field) {
            if (in_array($field, $fields, true)) {
                $this->forge->dropColumn('ai_event_detections', $field);
            }
        }
    }
}
