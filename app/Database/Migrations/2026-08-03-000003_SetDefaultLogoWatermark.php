<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class SetDefaultLogoWatermark extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('watermark_settings') && in_array('logo_path', $this->db->getFieldNames('watermark_settings'), true)) {
            $this->db->query("UPDATE `watermark_settings` SET `logo_path` = 'FU' WHERE `logo_path` IS NULL OR `logo_path` = ''");
        }
    }

    public function down()
    {
        // Keep the selected logo when rolling back; clearing it could discard an administrator choice.
    }
}
