<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class TrashSelectionViewTest extends CIUnitTestCase
{
    public function testAdminActionsAreHiddenUntilTrashSelection(): void
    {
        helper('dashboard');
        $html = view('workspace/trash', $this->viewData('admin'));

        $this->assertStringContainsString('id="trashSelectionActions" hidden', $html);
        $this->assertStringContainsString('Restore selected', $html);
        $this->assertStringContainsString('Delete permanently', $html);
        $this->assertStringContainsString("selectionActions.hidden = count === 0", $html);
        $this->assertStringContainsString("allVisibleSelected ? 'Unselect all' : 'Select all visible'", $html);
        $this->assertStringNotContainsString('id="clearTrashSelection"', $html);
        $this->assertStringContainsString('trash-bulk-count d-none', $html);
        $this->assertStringContainsString("selectionCount.classList.toggle('d-none', count === 0)", $html);
        $this->assertStringNotContainsString('workspace/trash/7/restore', $html);
        $this->assertStringNotContainsString('workspace/trash/7/delete', $html);
    }

    public function testStaffCanRestoreButCannotPermanentlyDelete(): void
    {
        helper('dashboard');
        $html = view('workspace/trash', $this->viewData('staff'));

        $this->assertStringContainsString('Restore selected', $html);
        $this->assertStringNotContainsString('Delete permanently', $html);
        $this->assertStringNotContainsString('workspace/trash/bulk-delete', $html);
    }

    public function testDeletedFolderRendersAsOneSelectableFolderCard(): void
    {
        helper('dashboard');
        $data = $this->viewData('admin');
        $data['files'] = [];
        $data['folders'] = [[
            'id' => 12,
            'name' => 'Event Photos',
            'description' => '',
            'path' => 'Event Photos',
            'deleted_at' => '2026-08-13 10:00:00',
            'reason' => 'Moved to Trash through File Management',
            'deleted_by_name' => 'Test User',
            'subfolder_count' => 2,
            'file_count' => 14,
            'size' => 4096,
            'trash_days_remaining' => 29,
            'trash_expires_at' => 'Sep 12, 2026',
        ]];

        $html = view('workspace/trash', $data);

        $this->assertStringContainsString('data-trash-type="folder"', $html);
        $this->assertStringContainsString('Event Photos', $html);
        $this->assertStringContainsString('14 files', $html);
        $this->assertStringContainsString('2 subfolders', $html);
        $this->assertStringContainsString("'folder_ids[]'", $html);
        $this->assertStringContainsString('workspace/trash/folders/12', $html);
    }

    public function testLongNamesThumbnailsAndUnknownDatesRenderSafely(): void
    {
        helper('dashboard');
        $data = $this->viewData('admin');
        $name = str_repeat('Long filename ', 18) . '<photo>.png';
        $data['files'][0]['filename'] = $name;
        $data['files'][0]['deleted_at'] = null;
        $data['files'][0]['last_action_at'] = null;
        $data['files'][0]['trash_expires_at'] = '-';
        $data['serverItems'] = [[
            'id'=>str_repeat('a', 32), 'name'=>$name, 'directory'=>false,
            'filetype'=>'png', 'size'=>100, 'path'=>'Old folder/' . $name,
            'deleted_at'=>'Unknown', 'original_unknown'=>true, 'trash_days_remaining'=>30,
            'trash_expires_at'=>'2026-11-17 10:00:00',
        ]];
        $html = view('workspace/trash', $data);
        $this->assertStringContainsString('title="' . esc($name) . '"', $html);
        $this->assertStringNotContainsString('<photo>', $html);
        $this->assertStringContainsString('/preview?thumbnail=1', $html);
        $this->assertStringContainsString('data-restore-destination="1"', $html);
        $this->assertStringContainsString('id="trashRestoreDestination" hidden', $html);
        $this->assertStringContainsString('Retention date unknown', $html);
        $this->assertStringContainsString('Deletion date not recorded', $html);
        $this->assertStringContainsString('Local Library', $html);
        $this->assertStringContainsString('Server Library', $html);
        $this->assertStringContainsString('loading="eager"', $html);
    }

    /** @return array<string, mixed> */
    private function viewData(string $role): array
    {
        return [
            'user' => ['employee_id' => 1, 'name' => 'Test User', 'role' => $role],
            'activePage' => 'trash',
            'title' => 'Trash',
            'files' => [[
                'id' => 7,
                'display_title' => 'Selected photo',
                'filename' => 'selected.jpg',
                'filetype' => 'jpg',
                'size' => 2048,
                'last_action_at' => '2026-08-13 10:00:00',
                'deleted_at' => '2026-08-13 10:00:00',
                'reason' => 'Test removal',
                'deleted_by_name' => 'Test User',
                'trash_days_remaining' => 29,
                'trash_expires_at' => 'Sep 12, 2026',
            ]],
            'folders' => [],
            'retentionDays' => 30,
            'purgedCount' => 0,
            'query' => '',
            'type' => '',
            'fileTypes' => [['filetype' => 'jpg']],
            'success' => null,
            'error' => null,
        ];
    }
}
