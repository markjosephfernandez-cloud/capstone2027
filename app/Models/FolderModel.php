<?php

namespace App\Models;

use CodeIgniter\Model;

class FolderModel extends Model
{
    protected $table         = 'folder';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = ['parent_id', 'name', 'description', 'path', 'created_at', 'publication_channel', 'published_at', 'published_by', 'is_deleted', 'deleted_at', 'deleted_by', 'deletion_reason', 'trash_root_id'];
}
