<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class EnsureVisibleLogoWatermark extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('watermark_settings') && in_array('opacity', $this->db->getFieldNames('watermark_settings'), true)) {
            $this->db->query('UPDATE `watermark_settings` SET `opacity` = 65 WHERE `opacity` < 30');
        }
    }

    public function down()
    {
        // Keep the readable opacity because reverting could make the logo effectively invisible again.
    }
}
