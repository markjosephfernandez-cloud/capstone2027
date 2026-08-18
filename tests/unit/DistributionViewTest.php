<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class DistributionViewTest extends CIUnitTestCase
{
    public function testFocusedDepartmentRequestHasDeepLinkTargetAndHighlight(): void
    {
        $html = view('distribution/index', [
            'user' => ['employee_id' => 0, 'name' => 'Test User', 'role' => 'admin'],
            'activePage' => 'distribution',
            'title' => 'Distribution Workflow',
            'requests' => [[
                'id' => 42,
                'department' => 'College of Computing Studies',
                'title' => 'Foundation Week photos',
                'description' => 'Event coverage',
                'status' => 'submitted',
                'submitted_at' => '2026-08-12 10:00:00',
                'requester_name' => 'Department User',
                'transmitter_name' => null,
                'transmitted_at' => null,
                'file_count' => 0,
            ]],
            'requestFiles' => [],
            'directSends' => [],
            'files' => [],
            'folders' => [[
                'id' => 7,
                'parent_id' => null,
                'name' => 'ID Pictures',
                'path' => 'ID Pictures',
                'file_count' => 3,
                'subfolder_count' => 2,
            ]],
            'departments' => [],
            'actions' => [],
            'canManage' => true,
            'isDepartment' => false,
            'focusedRequestId' => 42,
            'success' => null,
            'error' => null,
        ]);

        $this->assertStringContainsString('Department Requests', $html);
        $this->assertStringContainsString('id="request-42" class="request-row-focus"', $html);
        $this->assertStringContainsString('Foundation Week photos', $html);
        $this->assertStringContainsString('"subfolder_count":2', $html);
        $this->assertStringContainsString("subfolderCount + ' ' + (subfolderCount === 1 ? 'folder' : 'folders')", $html);
        $this->assertStringContainsString('--mini-primary-deep: #2348c7', $html);
        $this->assertStringContainsString('.mini-tile.is-selected { background: linear-gradient', $html);
    }
}
