<?php

namespace App\Models;

use CodeIgniter\Model;

class RecentModel extends Model
{
    protected $table         = 'recent';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['employee_id', 'item_type', 'item_name', 'filepath', 'timestamp'];
}
