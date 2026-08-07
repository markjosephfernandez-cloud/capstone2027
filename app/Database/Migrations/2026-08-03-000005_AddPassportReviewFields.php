<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddPassportReviewFields extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('passports')) {
            return;
        }
        $fields = $this->db->getFieldNames('passports');
        $columns = [];
        if (! in_array('reviewed_by', $fields, true)) {
            $columns['reviewed_by'] = ['type' => 'VARCHAR', 'constraint' => 50, 'null' => true, 'after' => 'approval_status'];
        }
        if (! in_array('reviewed_at', $fields, true)) {
            $columns['reviewed_at'] = ['type' => 'DATETIME', 'null' => true, 'after' => 'reviewed_by'];
        }
        if (! in_array('review_notes', $fields, true)) {
            $columns['review_notes'] = ['type' => 'TEXT', 'null' => true, 'after' => 'reviewed_at'];
        }
        if ($columns !== []) {
            $this->forge->addColumn('passports', $columns);
        }
    }

    public function down()
    {
        if ($this->db->tableExists('passports')) {
            $fields = array_intersect(['reviewed_by', 'reviewed_at', 'review_notes'], $this->db->getFieldNames('passports'));
            if ($fields !== []) {
                $this->forge->dropColumn('passports', array_values($fields));
            }
        }
    }
}
