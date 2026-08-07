<?php

namespace App\Models;

use CodeIgniter\Model;

class UserModel extends Model
{
    protected $table            = 'user';
    protected $primaryKey       = 'employee_id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $protectFields    = true;
    protected $allowedFields    = [
        'employee_id', 'name', 'department', 'position', 'email_address',
        'role', 'password', 'password_hash', 'is_active', 'created_at',
        'updated_at', 'last_login_at',
    ];
}
