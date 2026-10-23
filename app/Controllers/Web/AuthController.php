<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Exceptions\MisAuthenticationUnavailable;
use App\Models\UserModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;

class AuthController extends BaseController
{
    public function loginForm(): string|RedirectResponse
    {
        if (session()->get('isLoggedIn') === true) {
            return redirect()->to(site_url('dashboard'));
        }

        return view('auth/login', [
            'error'       => session()->getFlashdata('error'),
            'employee_id' => session()->getFlashdata('employee_id'),
        ]);
    }

    public function login(): RedirectResponse
    {
        $employeeId = filter_var($this->request->getPost('employee_id'), FILTER_VALIDATE_INT);
        $password   = $this->request->getPost('password');

        if ($employeeId === false || $employeeId < 1 || ! is_string($password) || $password === '') {
            return redirect()->to(site_url('login'))
                ->with('error', 'Enter your employee ID and password.')
                ->with('employee_id', (string) $this->request->getPost('employee_id'));
        }

        try {
            $user = service('credentials')->attempt((int) $employeeId, $password);
        } catch (MisAuthenticationUnavailable $e) {
            return redirect()->to(site_url('login'))
                ->with('error', $e->getMessage())
                ->with('employee_id', (string) $employeeId);
        }
        if ($user === null) {
            return redirect()->to(site_url('login'))
                ->with('error', 'Invalid employee ID or password.')
                ->with('employee_id', (string) $employeeId);
        }

        session()->regenerate(true);
        session()->set([
            'isLoggedIn' => true,
            'employee_id'=> (int) $user['employee_id'],
            'name'       => $user['name'],
            'department' => $user['department'],
            'position'   => $user['position'] ?? '',
            'role'       => $user['role'] ?? 'guest',
        ]);
        (new UserModel())->update((int) $user['employee_id'], ['last_login_at' => gmdate('Y-m-d H:i:s')]);
        service('audit')->record(session()->get(), $this->request, 'auth', 'LOGIN', 'user', (int) $user['employee_id'], 'Signed in to Greyhound Hub.');

        return redirect()->to(site_url('dashboard'));
    }

    public function logoutToken(): ResponseInterface
    {
        return $this->response->noCache()->setJSON([
            'csrfTokenName' => csrf_token(),
            'csrfToken' => csrf_hash(),
        ]);
    }

    public function logout(): RedirectResponse
    {
        if (session()->get('isLoggedIn') === true) {
            $actor = session()->get();
            service('audit')->record($actor, $this->request, 'auth', 'LOGOUT', 'user', (int) ($actor['employee_id'] ?? 0), 'Signed out of Greyhound Hub.');
        }
        session()->destroy();

        return redirect()->to(site_url('login'));
    }
}
