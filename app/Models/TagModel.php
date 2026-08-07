<?php

namespace App\Models;

use CodeIgniter\Model;

class TagModel extends Model
{
    protected $table         = 'tag';
    protected $primaryKey    = 'tag_id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['tag', 'type', 'filepath', 'file_id'];
}
