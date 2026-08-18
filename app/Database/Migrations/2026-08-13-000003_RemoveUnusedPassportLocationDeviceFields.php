<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RemoveUnusedPassportLocationDeviceFields extends Migration
{
    public function up()
    {
        if (! $this->db->tableExists('passports')) {
            return;
        }

        $fields = array_values(array_intersect(
            ['gps_location', 'device_info'],
            $this->db->getFieldNames('passports'),
        ));
        if ($fields !== []) {
            $this->forge->dropColumn('passports', $fields);
        }
    }

    public function down()
    {
        if (! $this->db->tableExists('passports')) {
            return;
        }

        $fields = $this->db->getFieldNames('passports');
        $columns = [];
        if (! in_array('gps_location', $fields, true)) {
            $columns['gps_location'] = [
                'type' => 'VARCHAR',
                'constraint' => 150,
                'null' => true,
                'after' => 'upload_date',
            ];
        }
        if (! in_array('device_info', $fields, true)) {
            $columns['device_info'] = [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
                'after' => 'gps_location',
            ];
        }
        if ($columns !== []) {
            $this->forge->addColumn('passports', $columns);
        }
    }
}
