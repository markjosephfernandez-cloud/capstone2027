<?php

namespace App\Controllers\Web;

use App\Controllers\BaseController;
use App\Libraries\RolePolicy;
use App\Models\UserModel;
use App\Services\ConsentService;
use App\Services\TrashService;
use CodeIgniter\HTTP\RedirectResponse;
use RuntimeException;

class AdminController extends BaseController
{
    public function controlCenter()
    {
        if (($redirect = $this->requireAdmin()) !== null) {
            return $redirect;
        }

        helper('dashboard');
        $db = db_connect();
        $search = trim(mb_substr((string) $this->request->getGet('q'), 0, 100));
        $roleFilter = trim((string) $this->request->getGet('role'));
        $statusFilter = trim((string) $this->request->getGet('status'));
        if (! in_array($roleFilter, ['', 'super_admin', 'admin', 'writer', 'department'], true)) {
            $roleFilter = '';
        }
        if (! in_array($statusFilter, ['', 'active', 'inactive'], true)) {
            $statusFilter = '';
        }

        $userCount = (int) $db->table('user')->countAllResults();
        $activeUsers = (int) $db->table('user')->where('is_active', 1)->countAllResults();
        $inactiveUsers = max(0, $userCount - $activeUsers);
        $adminCount = (int) $db->table('user')->where('role', 'super_admin')->where('is_active', 1)->countAllResults();
        $staffCount = (int) $db->table('user')->where('role', 'admin')->where('is_active', 1)->countAllResults();
        $activeFiles = (int) $db->table('files')->where('is_deleted', 0)->countAllResults();
        $trashCount = (int) $db->table('files')->where('is_deleted', 1)->countAllResults();
        $storageBytes = (int) ($db->table('files')->where('is_deleted', 0)->selectSum('size')->get()->getRowArray()['size'] ?? 0);
        $passportAiFlags = $db->tableExists('passports')
            ? (int) $db->table('passports p')
                ->join('files f', 'f.id = p.media_id')
                ->where('f.is_deleted', 0)
                ->where('p.needs_review', 1)
                ->countAllResults()
            : 0;
        $activeTokens = $db->tableExists('api_tokens') ? (int) $db->table('api_tokens')->where('revoked_at', null)->groupStart()->where('expires_at', null)->orWhere('expires_at >', gmdate('Y-m-d H:i:s'))->groupEnd()->countAllResults() : 0;
        $legacyPasswords = (int) $db->table('user')->groupStart()->where('password_hash', null)->orWhere('password_hash', '')->groupEnd()->countAllResults();
        $consentMissing = $db->tableExists('privacy_consents')
            ? (int) $db->table('user u')->join('privacy_consents pc', 'pc.employee_id = u.employee_id AND pc.policy_version = ' . $db->escape(ConsentService::POLICY_VERSION), 'left')->where('u.is_active', 1)->where('pc.id', null)->countAllResults()
            : 0;
        $expiredTrash = $db->tableExists('trashfiles')
            ? (int) $db->table('files f')->join('trashfiles tf', 'tf.file_id = f.id', 'left')->where('f.is_deleted', 1)->where('COALESCE(tf.deleted_at, f.last_action_at, f.dateupload) <= DATE_SUB(NOW(), INTERVAL ' . TrashService::RETENTION_DAYS . ' DAY)', null, false)->countAllResults()
            : 0;

        $teamQuery = $db->table('user u')
            ->select('u.employee_id, u.name, u.department, u.position, u.email_address, u.role, u.is_active, u.created_at, u.last_login_at, COUNT(f.id) AS media_count')
            ->join('files f', 'f.employee_id = u.employee_id AND f.is_deleted = 0', 'left', false);
        if ($search !== '') {
            $teamQuery->groupStart()->like('u.name', $search)->orLike('u.email_address', $search)->orLike('u.employee_id', $search)->orLike('u.position', $search)->groupEnd();
        }
        if ($roleFilter !== '') {
            $teamQuery->where('u.role', $roleFilter);
        }
        if ($statusFilter !== '') {
            $teamQuery->where('u.is_active', $statusFilter === 'active' ? 1 : 0);
        }
        $team = $teamQuery
            ->groupBy('u.employee_id, u.name, u.department, u.position, u.email_address, u.role, u.is_active, u.created_at, u.last_login_at')
            ->orderBy('u.is_active', 'DESC')
            ->orderBy("FIELD(u.role, 'super_admin', 'admin', 'writer', 'department')", '', false)
            ->orderBy('u.name', 'ASC')
            ->limit(200)
            ->get()->getResultArray();

        $activity = $db->tableExists('audit_logs')
            ? $db->table('audit_logs')->select('user_name, module, action, description, created_at')->orderBy('created_at', 'DESC')->limit(12)->get()->getResultArray()
            : [];
        $storagePath = service('storage')->root();
        $systemHealth = [
            ['label' => 'Database', 'detail' => $db->getDatabase(), 'healthy' => true, 'icon' => 'bi-database-check'],
            ['label' => 'Media storage', 'detail' => is_writable($storagePath) ? 'Writable' : 'Not writable', 'healthy' => is_dir($storagePath) && is_writable($storagePath), 'icon' => 'bi-hdd-stack'],
            ['label' => 'Image processing', 'detail' => extension_loaded('gd') ? 'PHP GD available' : 'PHP GD missing', 'healthy' => extension_loaded('gd'), 'icon' => 'bi-images'],
            ['label' => 'AI endpoint', 'detail' => trim((string) env('greyhound.eventModelEndpoint', '')) !== '' ? 'Configured' : 'Not configured', 'healthy' => trim((string) env('greyhound.eventModelEndpoint', '')) !== '', 'icon' => 'bi-cpu'],
        ];

        return view('admin/control_center', [
            'user' => session()->get(), 'activePage' => 'admin-control', 'title' => 'Admin Control Center',
            'success' => session()->getFlashdata('success'), 'error' => session()->getFlashdata('error'),
            'search' => $search, 'roleFilter' => $roleFilter, 'statusFilter' => $statusFilter,
            'stats' => compact('userCount', 'activeUsers', 'inactiveUsers', 'adminCount', 'staffCount', 'activeFiles', 'trashCount', 'storageBytes', 'passportAiFlags', 'activeTokens', 'legacyPasswords', 'consentMissing', 'expiredTrash'),
            'team' => $team, 'activity' => $activity, 'systemHealth' => $systemHealth,
        ]);
    }

    public function createUser()
    {
        if (($redirect = $this->requireAdmin()) !== null) return $redirect;
        $employeeId = filter_var($this->request->getPost('employee_id'), FILTER_VALIDATE_INT);
        try {
            if ($employeeId === false || $employeeId < 1) throw new RuntimeException('Enter a valid employee ID.');
            if ((new UserModel())->find((int) $employeeId) !== null) throw new RuntimeException('That employee ID already exists.');
            $profile = $this->validatedProfile();
            $password = (string) $this->request->getPost('password');
            $this->validatePassword($password, (string) $this->request->getPost('password_confirm'));
            (new UserModel())->insert(array_merge($profile, ['employee_id' => (int) $employeeId, 'password' => '', 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'is_active' => 1, 'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s')]));
        } catch (RuntimeException $exception) {
            return redirect()->to(site_url('admin/control-center#users'))->with('error', $exception->getMessage());
        }
        $actor = session()->get();
        service('audit')->record($actor, $this->request, 'admin', 'USER_CREATED', 'user', (int) $employeeId, 'Created ' . $profile['role'] . ' account for ' . $profile['name'] . '.');
        return redirect()->to(site_url('admin/control-center#users'))->with('success', 'Account created for ' . $profile['name'] . '.');
    }

    public function updateUser(int $employeeId)
    {
        if (($redirect = $this->requireAdmin()) !== null) return $redirect;
        $model = new UserModel();
        $target = $model->find($employeeId);
        if ($target === null) return redirect()->to(site_url('admin/control-center#users'))->with('error', 'The selected account is unavailable.');
        try {
            $profile = $this->validatedProfile();
            $actorId = (int) session()->get('employee_id');
            if ($employeeId === $actorId && $profile['role'] !== 'super_admin') throw new RuntimeException('You cannot remove your own Super Admin role.');
            if ((string) $target['role'] === 'super_admin' && $profile['role'] !== 'super_admin' && (int) ($target['is_active'] ?? 1) === 1 && $this->activeAdminCount() <= 1) throw new RuntimeException('At least one active Super Admin account must remain.');
            $profile['updated_at'] = gmdate('Y-m-d H:i:s');
            $model->update($employeeId, $profile);
            if ($employeeId === $actorId) session()->set(['name' => $profile['name'], 'department' => $profile['department'], 'position' => $profile['position']]);
        } catch (RuntimeException $exception) {
            return redirect()->to(site_url('admin/control-center#users'))->with('error', $exception->getMessage());
        }
        service('audit')->record(session()->get(), $this->request, 'admin', 'USER_UPDATED', 'user', $employeeId, 'Updated account profile and role for ' . $profile['name'] . '.');
        return redirect()->to(site_url('admin/control-center#users'))->with('success', 'Account updated successfully.');
    }

    public function toggleUser(int $employeeId)
    {
        if (($redirect = $this->requireAdmin()) !== null) return $redirect;
        $model = new UserModel();
        $target = $model->find($employeeId);
        if ($target === null) return redirect()->to(site_url('admin/control-center#users'))->with('error', 'The selected account is unavailable.');
        if ($employeeId === (int) session()->get('employee_id')) return redirect()->to(site_url('admin/control-center#users'))->with('error', 'You cannot deactivate your own account.');
        $activate = (int) ($target['is_active'] ?? 1) !== 1;
        if (! $activate && (string) $target['role'] === 'super_admin' && $this->activeAdminCount() <= 1) return redirect()->to(site_url('admin/control-center#users'))->with('error', 'At least one active Super Admin account must remain.');
        $model->update($employeeId, ['is_active' => $activate ? 1 : 0, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        if (! $activate) $this->revokeUserTokens($employeeId);
        $action = $activate ? 'USER_ACTIVATED' : 'USER_DEACTIVATED';
        service('audit')->record(session()->get(), $this->request, 'admin', $action, 'user', $employeeId, ($activate ? 'Activated' : 'Deactivated') . ' account for ' . ($target['name'] ?? $employeeId) . '.');
        return redirect()->to(site_url('admin/control-center#users'))->with('success', 'Account ' . ($activate ? 'activated.' : 'deactivated and API access revoked.'));
    }

    public function resetPassword(int $employeeId)
    {
        if (($redirect = $this->requireAdmin()) !== null) return $redirect;
        $model = new UserModel();
        $target = $model->find($employeeId);
        if ($target === null) return redirect()->to(site_url('admin/control-center#users'))->with('error', 'The selected account is unavailable.');
        $password = (string) $this->request->getPost('password');
        try { $this->validatePassword($password, (string) $this->request->getPost('password_confirm')); }
        catch (RuntimeException $exception) { return redirect()->to(site_url('admin/control-center#users'))->with('error', $exception->getMessage()); }
        $model->update($employeeId, ['password' => '', 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'updated_at' => gmdate('Y-m-d H:i:s')]);
        $revoked = $this->revokeUserTokens($employeeId);
        service('audit')->record(session()->get(), $this->request, 'admin', 'PASSWORD_RESET', 'user', $employeeId, 'Reset the password for ' . ($target['name'] ?? $employeeId) . ' and revoked ' . $revoked . ' active API token(s).');
        return redirect()->to(site_url('admin/control-center#users'))->with('success', 'Password reset and active API tokens revoked.');
    }

    public function revokeTokens(int $employeeId)
    {
        if (($redirect = $this->requireAdmin()) !== null) return $redirect;
        if ((new UserModel())->find($employeeId) === null) return redirect()->to(site_url('admin/control-center#users'))->with('error', 'The selected account is unavailable.');
        $count = $this->revokeUserTokens($employeeId);
        service('audit')->record(session()->get(), $this->request, 'admin', 'TOKENS_REVOKED', 'user', $employeeId, 'Revoked ' . $count . ' active API token(s).');
        return redirect()->to(site_url('admin/control-center#users'))->with('success', $count . ' active API token(s) revoked.');
    }

    public function sendConsentReminders()
    {
        if (($redirect = $this->requireAdmin()) !== null) return $redirect;
        $db = db_connect();
        if (! $db->tableExists('privacy_consents') || ! $db->tableExists('notifications')) return redirect()->to(site_url('admin/control-center'))->with('error', 'Consent notifications are unavailable.');
        $users = $db->table('user u')->select('u.employee_id, u.role')->join('privacy_consents pc', 'pc.employee_id = u.employee_id AND pc.policy_version = ' . $db->escape(ConsentService::POLICY_VERSION), 'left')->where('u.is_active', 1)->where('pc.id', null)->get()->getResultArray();
        $sent = 0;
        foreach ($users as $user) {
            $existing = $db->table('notifications')->where('recipient_id', (string) $user['employee_id'])->where('type', 'privacy_consent_reminder')->where('is_read', 0)->countAllResults();
            if ($existing > 0) continue;
            $db->table('notifications')->insert(['recipient_id' => (string) $user['employee_id'], 'recipient_role' => (string) $user['role'], 'type' => 'privacy_consent_reminder', 'title' => 'Data Privacy acknowledgement required', 'message' => 'Please sign in and acknowledge the current Greyhound Hub data privacy and media-use notice.', 'link' => site_url('dashboard'), 'is_read' => 0, 'created_at' => gmdate('Y-m-d H:i:s')]);
            ++$sent;
        }
        service('audit')->record(session()->get(), $this->request, 'admin', 'CONSENT_REMINDERS_SENT', 'privacy_consent', null, 'Sent ' . $sent . ' new consent reminder(s).');
        return redirect()->to(site_url('admin/control-center'))->with('success', $sent . ' new privacy-consent reminder(s) sent.');
    }

    public function purgeExpiredTrash()
    {
        if (($redirect = $this->requireAdmin()) !== null) return $redirect;
        $actor = session()->get();
        $purged = (new TrashService(db_connect()))->purgeExpiredTrash($actor, $this->request, 500);
        service('audit')->record($actor, $this->request, 'admin', 'TRASH_RETENTION_RUN', 'trash', null, 'Ran the 30-day Trash retention cleanup; purged ' . $purged . ' media item(s).');
        return redirect()->to(site_url('admin/control-center'))->with('success', $purged . ' expired Trash item(s) permanently removed.');
    }

    private function requireAdmin(): ?RedirectResponse
    {
        if (session()->get('isLoggedIn') !== true) return redirect()->to(site_url('login'));
        if (! RolePolicy::isSuperAdmin(session()->get())) return redirect()->to(site_url('dashboard'))->with('error', 'Super Admin access is required to open the Control Center.');
        return null;
    }

    /** @return array{name:string,department:string,position:string,email_address:string,role:string} */
    private function validatedProfile(): array
    {
        $name = trim(mb_substr((string) $this->request->getPost('name'), 0, 150));
        $department = trim(mb_substr((string) $this->request->getPost('department'), 0, 150));
        $position = trim(mb_substr((string) $this->request->getPost('position'), 0, 150));
        $email = trim(mb_substr((string) $this->request->getPost('email_address'), 0, 255));
        $role = trim((string) $this->request->getPost('role'));
        if (mb_strlen($name) < 2 || $department === '' || $position === '') throw new RuntimeException('Name, department, and position are required.');
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Enter a valid email address.');
        if (! in_array($role, ['super_admin', 'admin', 'writer', 'department'], true)) throw new RuntimeException('Choose a valid system role.');
        return ['name' => $name, 'department' => $department, 'position' => $position, 'email_address' => $email, 'role' => $role];
    }

    private function validatePassword(string $password, string $confirmation): void
    {
        if ($password !== $confirmation) throw new RuntimeException('The password confirmation does not match.');
        if (strlen($password) < 8 || strlen($password) > 128 || preg_match('/[A-Za-z]/', $password) !== 1 || preg_match('/\d/', $password) !== 1) throw new RuntimeException('Password must be 8-128 characters and contain at least one letter and one number.');
    }

    private function activeAdminCount(): int
    {
        return (int) db_connect()->table('user')->where('role', 'super_admin')->where('is_active', 1)->countAllResults();
    }

    private function revokeUserTokens(int $employeeId): int
    {
        $db = db_connect();
        if (! $db->tableExists('api_tokens')) return 0;
        $builder = $db->table('api_tokens')->where('user_employee_id', $employeeId)->where('revoked_at', null);
        $count = $builder->countAllResults(false);
        if ($count > 0) $builder->update(['revoked_at' => gmdate('Y-m-d H:i:s')]);
        return $count;
    }
}
