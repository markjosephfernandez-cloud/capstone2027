<?php

namespace App\Models;

use CodeIgniter\Model;

class ApiTokenModel extends Model
{
    protected $table         = 'api_tokens';
    protected $returnType    = 'array';
    protected $useTimestamps = false;
    protected $allowedFields = [
        'user_employee_id', 'token_hash', 'token_name', 'expires_at',
        'last_used_at', 'revoked_at', 'created_at',
    ];
}
