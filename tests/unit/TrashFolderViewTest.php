<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class TrashFolderViewTest extends CIUnitTestCase
{
    public function testDeletedFolderContentsAndWholeTreeActionsRender(): void
    {
        helper('dashboard');
        $html = view('workspace/trash_folder', [
            'user' => ['employee_id' => 1, 'name' => 'Test Admin', 'role' => 'admin'],
            'activePage' => 'trash',
            'title' => 'Trash - Event Photos',
            'folder' => ['id' => 12, 'name' => 'Event Photos'],
            'rootFolder' => ['id' => 12, 'name' => 'Event Photos', 'trash_days_remaining' => 29],
            'breadcrumbs' => [['id' => 12, 'name' => 'Event Photos']],
            'children' => [['id' => 13, 'name' => 'Day One']],
            'files' => [[
                'id' => 7,
                'display_title' => 'Opening photo',
                'filename' => 'opening.jpg',
                'filetype' => 'jpg',
                'mime_type' => 'image/jpeg',
                'size' => 2048,
            ]],
            'summary' => ['file_count' => 1, 'size' => 2048],
            'canRestore' => true,
            'canPermanentDelete' => true,
            'success' => null,
            'error' => null,
        ]);

        $this->assertStringContainsString('Folder contents', $html);
        $this->assertStringContainsString('workspace/trash/folders/13', $html);
        $this->assertStringContainsString('Opening photo', $html);
        $this->assertStringContainsString('name="folder_ids[]" value="12"', $html);
        $this->assertStringContainsString('Restore folder', $html);
        $this->assertStringContainsString('Delete folder', $html);
    }
}
