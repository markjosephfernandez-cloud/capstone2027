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
        $this->assertStringContainsString('Delete selected', $html);
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
        $this->assertStringNotContainsString('Delete selected', $html);
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
