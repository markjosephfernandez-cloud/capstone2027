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

    public function testSuperAdminOverridesEveryPermission(): void
    {
        $this->assertTrue(RolePolicy::allows(['role' => 'super_admin'], 'system.any_permission'));
    }
}
