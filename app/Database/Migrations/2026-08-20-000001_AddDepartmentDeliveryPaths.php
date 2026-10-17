<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Records the physical department copy created for each delivery. */
class AddDepartmentDeliveryPaths extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('media_assignments') && ! in_array('delivery_filepath', $this->db->getFieldNames('media_assignments'), true)) {
            $this->forge->addColumn('media_assignments', [
                'delivery_filepath' => ['type' => 'TEXT', 'null' => true, 'after' => 'media_id'],
            ]);
        }

        if ($this->db->tableExists('media_assignment_folders') && ! in_array('delivery_path', $this->db->getFieldNames('media_assignment_folders'), true)) {
            $this->forge->addColumn('media_assignment_folders', [
                'delivery_path' => ['type' => 'TEXT', 'null' => true, 'after' => 'folder_id'],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->tableExists('media_assignments') && in_array('delivery_filepath', $this->db->getFieldNames('media_assignments'), true)) {
            $this->forge->dropColumn('media_assignments', 'delivery_filepath');
        }
        if ($this->db->tableExists('media_assignment_folders') && in_array('delivery_path', $this->db->getFieldNames('media_assignment_folders'), true)) {
            $this->forge->dropColumn('media_assignment_folders', 'delivery_path');
        }
    }
}
