<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddDislikesToMobileMediaLikes extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('mobile_media_likes')
            && ! in_array('reaction', $this->db->getFieldNames('mobile_media_likes'), true)) {
            $this->forge->addColumn('mobile_media_likes', [
                'reaction' => [
                    'type' => 'ENUM',
                    'constraint' => ['like', 'dislike'],
                    'default' => 'like',
                    'after' => 'created_at',
                ],
            ]);
        }
    }

    public function down()
    {
        if ($this->db->tableExists('mobile_media_likes')
            && in_array('reaction', $this->db->getFieldNames('mobile_media_likes'), true)) {
            $this->forge->dropColumn('mobile_media_likes', 'reaction');
        }
    }
}
