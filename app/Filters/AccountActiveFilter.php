<?php

namespace App\Filters;

use App\Models\UserModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AccountActiveFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        if (session()->get('isLoggedIn') !== true) {
            return null;
        }
        $employeeId = (int) session()->get('employee_id');
        $user = $employeeId > 0 ? (new UserModel())->find($employeeId) : null;
        if ($user !== null && (int) ($user['is_active'] ?? 1) === 1) {
            session()->set([
                'name' => (string) ($user['name'] ?? ''),
                'department' => (string) ($user['department'] ?? ''),
                'position' => (string) ($user['position'] ?? ''),
                'role' => (string) ($user['role'] ?? 'guest'),
            ]);
            return null;
        }

        session()->destroy();
        return redirect()->to(site_url('login'))->with('error', 'This account is inactive. Contact an administrator if you need access restored.');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
