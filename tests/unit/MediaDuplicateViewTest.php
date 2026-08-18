<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class MediaDuplicateViewTest extends CIUnitTestCase
{
    public function testFileManagementRendersGroupedDuplicateModal(): void
    {
        helper('dashboard');
        $html = view('workspace/media', [
            'user' => ['employee_id' => 0, 'name' => 'Test Admin', 'role' => 'admin'],
            'activePage' => 'file-management',
            'title' => 'File Management',
            'files' => [[
                'id' => 12,
                'display_title' => 'Graduation Portrait',
                'filetype' => 'jpg',
                'mime_type' => 'image/jpeg',
                'size' => 2048,
                'dateupload' => '2026-08-13 10:00:00',
                'tags' => 'graduation, portrait',
                'classification' => 'Graduation Activities',
                'detected_objects' => 'person',
                'description' => '',
                'folder_id' => 7,
                'containing_folder_id' => 7,
                'folder_display_path' => 'Pillar / 2026 / ID Pictures',
            ], [
                'id' => 13,
                'display_title' => 'Root Portrait',
                'filetype' => 'jpg',
                'mime_type' => 'image/jpeg',
                'size' => 1024,
                'dateupload' => '2026-08-13 11:00:00',
                'tags' => 'graduation',
                'classification' => 'Graduation Activities',
                'detected_objects' => 'person',
                'description' => '',
                'folder_id' => null,
                'containing_folder_id' => null,
                'folder_display_path' => 'Root Library',
            ]],
            'fileTypes' => [],
            'folders' => [[
                'id' => 7,
                'name' => 'ID Pictures',
                'description' => '',
                'path' => 'ID Pictures',
                'created_at' => '2026-08-13 10:00:00',
                'file_count' => 3,
                'subfolder_count' => 2,
            ]],
            'folderOptions' => [],
            'selectedFolder' => null,
            'parentFolder' => null,
            'selectedFolderPath' => 'All media',
            'folderId' => null,
            'query' => 'graduation',
            'type' => '',
            'stage' => '',
            'distribution' => '',
            'canManage' => true,
            'canUpload' => true,
            'canToggleWatermark' => true,
            'watermarkEnabled' => true,
            'isDepartment' => false,
            'duplicateCount' => 1,
            'duplicateFileCount' => 1,
            'duplicateGroups' => [[
                'original' => [
                    'id' => 10,
                    'display_title' => 'Original photo',
                    'filename' => 'original.jpg',
                    'filetype' => 'jpg',
                    'mime_type' => 'image/jpeg',
                    'size' => 2048,
                    'dateupload' => '2026-08-12 10:00:00',
                    'watermarked' => true,
                ],
                'duplicates' => [[
                    'id' => 20,
                    'display_title' => 'Similar photo',
                    'filename' => 'similar.jpg',
                    'mime_type' => 'image/jpeg',
                    'size' => 1900,
                    'status' => 'pending',
                    'created_at' => '2026-08-12 10:05:00',
                    'uploader_name' => 'Test Admin',
                    'match_type' => 'visual',
                    'similarity_score' => 95.0,
                    'detection_scope' => 'upload_batch',
                ]],
                'similarity' => 95.0,
            ]],
            'departments' => [],
            'openRequests' => [],
            'success' => null,
            'error' => null,
        ]);

        $this->assertStringContainsString('data-bs-target="#duplicatesModal"', $html);
        $this->assertStringContainsString('Duplicate Groups', $html);
        $this->assertStringContainsString('Visual duplicate', $html);
        $this->assertStringContainsString('95% similar or better', $html);
        $this->assertStringContainsString('id="restoreSelectedDuplicates"', $html);
        $this->assertStringContainsString('workspace/file-management/duplicates/restore', $html);
        $this->assertStringContainsString("input.name='duplicate_ids[]'", $html);
        $this->assertStringNotContainsString('Export Duplicate Report', $html);
        $this->assertStringContainsString('Duplicate scan mode', $html);
        $this->assertStringContainsString('value="upload_batch"', $html);
        $this->assertStringContainsString('value="media_library" checked', $html);
        $this->assertStringContainsString('Current upload', $html);
        $this->assertStringContainsString('id="automaticWatermarkToggle" checked', $html);
        $this->assertStringContainsString('Apply watermark automatically', $html);
        $this->assertStringContainsString('workspace/file-management/watermark-auto', $html);
        $this->assertStringContainsString('3 files &middot; 2 folders', $html);
        $this->assertStringContainsString('<span>Contents</span>', $html);
        $this->assertStringContainsString('Pillar / 2026 / ID Pictures', $html);
        $this->assertStringContainsString('workspace/file-management?folder=7', $html);
        $this->assertStringContainsString('Root Library', $html);
        $this->assertSame(4, substr_count($html, 'class="file-location-link"'));
        $this->assertStringContainsString('Search filenames, folder names, original dates taken, event names, and tags.', $html);
        $this->assertStringContainsString('placeholder="Search filename, folder, date taken, event, or tag"', $html);
        $this->assertStringNotContainsString('filterVisibleLibraryItems', $html);
        $this->assertStringContainsString('id="mediaFilterForm"', $html);
        $this->assertStringContainsString('data-ajax-live-search', $html);
        $this->assertStringContainsString('data-ajax-live-delay="400"', $html);
        $this->assertStringContainsString('data-ajax-search-status', $html);
        $this->assertStringContainsString('Results update automatically as you type.', $html);
        $this->assertStringNotContainsString('submitLiveSearch', $html);
    }
}
