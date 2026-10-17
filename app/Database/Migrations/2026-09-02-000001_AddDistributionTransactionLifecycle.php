<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Adds review cycles and explicit authorization to the existing distribution workflow. */
class AddDistributionTransactionLifecycle extends Migration
{
    public function up()
    {
        $this->columns('media_requests', [
            'transaction_code' => ['type' => 'VARCHAR', 'constraint' => 40, 'null' => true, 'after' => 'id'],
            'transaction_type' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'request', 'after' => 'transaction_code'],
            'authorization_status' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'not_authorized', 'after' => 'status'],
            'current_cycle' => ['type' => 'INT', 'constraint' => 11, 'default' => 0, 'after' => 'authorization_status'],
            'completion_feedback' => ['type' => 'TEXT', 'null' => true, 'after' => 'current_cycle'],
            'original_sender' => ['type' => 'INT', 'constraint' => 11, 'null' => true, 'after' => 'requested_by'],
        ]);
        $this->columns('media_assignments', [
            'cycle_number' => ['type' => 'INT', 'constraint' => 11, 'default' => 1, 'after' => 'status'],
            'submission_type' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'original', 'after' => 'cycle_number'],
        ]);
        $this->columns('media_assignment_folders', [
            'cycle_number' => ['type' => 'INT', 'constraint' => 11, 'default' => 1, 'after' => 'status'],
            'submission_type' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'original', 'after' => 'cycle_number'],
        ]);
        $this->columns('distribution_actions', [
            'cycle_number' => ['type' => 'INT', 'constraint' => 11, 'default' => 0, 'after' => 'action'],
            'transaction_status' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'cycle_number'],
            'authorization_status' => ['type' => 'VARCHAR', 'constraint' => 30, 'null' => true, 'after' => 'transaction_status'],
            'feedback' => ['type' => 'TEXT', 'null' => true, 'after' => 'authorization_status'],
            'items_json' => ['type' => 'LONGTEXT', 'null' => true, 'after' => 'feedback'],
        ]);

        if ($this->db->tableExists('media_requests')) {
            $this->db->query("UPDATE media_requests SET transaction_code = CONCAT('TRX-', DATE_FORMAT(submitted_at, '%Y'), '-', LPAD(id, 6, '0')) WHERE transaction_code IS NULL OR transaction_code = ''");
            $this->db->query("UPDATE media_requests SET status = 'pending', authorization_status = 'not_authorized' WHERE status IN ('transmitted','sent')");
            $this->db->query("UPDATE media_requests SET status = 'complete', authorization_status = 'authorized', completion_feedback = COALESCE(NULLIF(completion_feedback, ''), 'Completed before transaction feedback was introduced.') WHERE status = 'completed'");
        }
    }

    public function down()
    {
        $this->drop('distribution_actions', ['cycle_number', 'transaction_status', 'authorization_status', 'feedback', 'items_json']);
        $this->drop('media_assignment_folders', ['cycle_number', 'submission_type']);
        $this->drop('media_assignments', ['cycle_number', 'submission_type']);
        $this->drop('media_requests', ['transaction_code', 'transaction_type', 'authorization_status', 'current_cycle', 'completion_feedback', 'original_sender']);
    }

    /** @param array<string,array<string,mixed>> $columns */
    private function columns(string $table, array $columns): void
    {
        if (! $this->db->tableExists($table)) return;
        $existing = $this->db->getFieldNames($table);
        foreach ($columns as $name => $definition) {
            if (! in_array($name, $existing, true)) $this->forge->addColumn($table, [$name => $definition]);
        }
    }

    /** @param list<string> $columns */
    private function drop(string $table, array $columns): void
    {
        if (! $this->db->tableExists($table)) return;
        $existing = $this->db->getFieldNames($table);
        $drop = array_values(array_intersect($columns, $existing));
        if ($drop !== []) $this->forge->dropColumn($table, $drop);
    }
}
