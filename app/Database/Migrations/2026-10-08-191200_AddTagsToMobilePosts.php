<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class AddTagsToMobilePosts extends Migration
{
    public function up()
    {
        $this->forge->addColumn('mobile_posts', ['tags' => ['type' => 'TEXT', 'null' => true]]);
    }
    public function down()
    {
        $this->forge->dropColumn('mobile_posts', 'tags');
    }
}
