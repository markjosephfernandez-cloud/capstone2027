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
            'requestFiles' => [42 => [
                ['id'=>11, 'filename'=>'photo.jpg', 'filetype'=>'jpg', 'mime_type'=>'image/jpeg'],
                ['id'=>12, 'filename'=>'trailer.mp4', 'filetype'=>'mp4', 'mime_type'=>'video/mp4'],
                ['id'=>13, 'filename'=>'notes.pdf', 'filetype'=>'pdf', 'mime_type'=>'application/pdf'],
            ]],
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
        $this->assertStringContainsString('data-bs-target="#serverTransmitModal"', $html);
        $this->assertStringContainsString('workspace/distribution/requests/42/transmit-server', html_entity_decode($html, ENT_QUOTES));
        $this->assertStringContainsString('<h6>Server Library</h6>', $html);
        preg_match('/var requestFiles = (.*);/', $html, $matches);
        $previews = json_decode($matches[1], true)[42];
        $this->assertSame(['image','video','file'], array_column($previews, 'preview_kind'));
        $this->assertStringEndsWith('/files/11/preview?thumbnail=1', $previews[0]['preview_url']);
        $this->assertStringEndsWith('/files/12/preview?thumbnail=1', $previews[1]['preview_url']);
        $this->assertNull($previews[2]['preview_url']);
        $this->assertStringContainsString('id="request-42" class="request-row-focus"', $html);
        $this->assertStringContainsString('Foundation Week photos', $html);
        $this->assertStringContainsString('"subfolder_count":2', $html);
        $this->assertStringContainsString("subfolderCount + ' ' + (subfolderCount === 1 ? 'folder' : 'folders')", $html);
        $this->assertStringContainsString('--mini-primary-deep: #2348c7', $html);
        $this->assertStringContainsString('.mini-tile.is-selected { background: linear-gradient', $html);
        $this->assertStringContainsString('class="btn btn-light d-none" type="button" id="previewBack"', $html);
        $this->assertStringContainsString("back.classList.toggle('d-none', currentFolder === null)", $html);
        $this->assertStringContainsString('id="pickerSelectAll"', $html);
        $this->assertStringContainsString("allVisibleSelected ? 'Unselect all' : 'Select all'", $html);
        $this->assertStringContainsString("document.getElementById('pickerSelectedCount')", $html);
    }
}
