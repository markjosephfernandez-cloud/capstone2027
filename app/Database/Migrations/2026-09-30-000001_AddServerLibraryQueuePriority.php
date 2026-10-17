<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddServerLibraryQueuePriority extends Migration
{
    public function up()
    {
        $this->forge->addColumn('server_library_scan_queue', [
            'priority' => ['type' => 'SMALLINT', 'default' => 0],
        ]);
        $this->forge->addKey(['source_key', 'priority', 'id'], false, false, 'server_queue_priority');
        $this->forge->processIndexes('server_library_scan_queue');
    }

    public function down()
    {
        $this->forge->dropKey('server_library_scan_queue', 'server_queue_priority');
        $this->forge->dropColumn('server_library_scan_queue', 'priority');
    }
}
