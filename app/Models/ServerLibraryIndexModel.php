<?php
namespace App\Models;

use CodeIgniter\Model;

class ServerLibraryIndexModel extends Model
{
    protected $table = 'server_library_index';
    protected $primaryKey = 'id';
    protected $returnType = 'array';
    protected $allowedFields = ['source_key','path_hash','parent_hash','path','name','is_dir','size','modified_at','extension','tags','classification','date_taken','uploaded_at','run_id','indexed_at'];
}
