<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddMobilePublishingFields extends Migration
{
    public function up()
    {
        $this->forge->addColumn('files', [
            'media_kind' => [
                'type'       => 'ENUM',
                'constraint' => ['image', 'video', 'audio', 'document'],
                'null'       => true,
                'after'      => 'filetype',
            ],
            'release_status' => [
                'type'       => 'ENUM',
                'constraint' => [
                    'draft',
                    'in_review',
                    'approved',
                    'published',
                    'withdrawn',
                ],
                'default' => 'draft',
                'after'   => 'featured',
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'last_action_at',
            ],
        ]);
    }

    public function down()
    {
        $this->forge->dropColumn('files', [
            'media_kind',
            'release_status',
            'updated_at',
        ]);
    }
}