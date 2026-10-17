<?php

use App\Libraries\RolePolicy;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class RolePolicyTest extends CIUnitTestCase
{
    public function testCreativeAdminCanManageButWriterCannot(): void
    {
        $this->assertTrue(RolePolicy::allows(['role' => 'admin'], 'files.upload'));
        $this->assertTrue(RolePolicy::allows(['role' => 'admin'], 'distribution.manage'));
        $this->assertTrue(RolePolicy::allows(['role' => 'admin'], 'files.delete_permanently'));
        $this->assertFalse(RolePolicy::allows(['role' => 'staff'], 'files.delete_permanently'));
        $this->assertFalse(RolePolicy::allows(['role' => 'writer'], 'files.upload'));
        $this->assertTrue(RolePolicy::allows(['role' => 'writer'], 'files.download'));
    }

    public function testDepartmentIsLimitedToAssignedFilesAndOwnRequests(): void
    {
        $actor = ['role' => 'department'];
        $this->assertTrue(RolePolicy::allows($actor, 'files.view_assigned'));
        $this->assertTrue(RolePolicy::allows($actor, 'requests.manage_own'));
        $this->assertFalse(RolePolicy::allows($actor, 'files.view_all'));
        $this->assertFalse(RolePolicy::allows($actor, 'files.manage'));
    }

    public function testServerHealthEndpointRejectsNonSuperAdminRoles(): void
    {
        foreach (['admin', 'staff', 'writer', 'department'] as $role) {
            $session = $this->createMock(\CodeIgniter\Session\Session::class);
            $actor = ['isLoggedIn'=>true, 'role'=>$role];
            $session->method('get')->willReturnCallback(static fn ($key = null) => $key === null ? $actor : ($actor[$key] ?? null));
            $session->expects($this->never())->method('close');
            \Config\Services::injectMock('session', $session);
            $response = (new \App\Controllers\Web\AdminController())->serverHealth();
            $this->assertInstanceOf(\CodeIgniter\HTTP\RedirectResponse::class, $response);
            $this->assertStringEndsWith('/dashboard', $response->getHeaderLine('Location'));
        }
    }

    public function testSuperAdminOverridesEveryPermission(): void
    {
        $this->assertTrue(RolePolicy::allows(['role' => 'super_admin'], 'system.any_permission'));
    }
}
