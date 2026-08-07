<?php

namespace App\Models;

use CodeIgniter\Model;

class FileModel extends Model
{
    protected $table         = 'files';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'filename', 'display_title', 'filepath', 'filetype', 'mime_type', 'media_kind', 'size', 'dateupload', 'description',
        'last_scanned', 'filehash', 'detected_objects', 'classification', 'pose',
        'gesture', 'datecreated', 'emotion', 'content_hash', 'scan_id',
        'bounding_boxes', 'duplicate_of', 'duplicate_count', 'new_scanned', 'featured', 'folder_id',
        'employee_id', 'is_deleted', 'last_action', 'last_action_at', 'tags',
        'publication_channel', 'published_at', 'release_status', 'storage_stage',
        'distribution_type', 'distribution_target', 'processed_at', 'updated_at',
        'downloadable',
    ];
}
