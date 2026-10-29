<?php
namespace App\Database\Seeds;

class Account_Seeder extends \CodeIgniter\Database\Seeder
{
    public function run()
    {
        $model = new \App\Models\UserModel();
        if ($model->find(20230182) !== null) {
            throw new \RuntimeException('Employee ID already exists; no changes made.');
        }
        $password = bin2hex(random_bytes(8)) . '!Gh7';
        $now = gmdate('Y-m-d H:i:s');
        $model->insert([
            'employee_id' => 20230182,
            'name' => 'Demo Administrator',
            'department' => 'Demo Department',
            'position' => 'Demo Administrator',
            'email_address' => 'demo@example.test',
            'role' => 'Super Admin',
            'is_active' => 1,
            'password' => '',
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $user = $model->find(20230182);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new \RuntimeException('Account verification failed.');
        }
        echo "Verified demo admin. Employee ID: 20230182; Password: " . $password . PHP_EOL;
    }
}