<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class MediaDuplicateViewTest extends CIUnitTestCase
{
    public function testFileManagementOmitsRemovedDuplicateQueue(): void
    {
        helper('dashboard');
        $data = [
            'user' => ['employee_id' => 0, 'name' => 'Test Admin', 'role' => 'admin'],
            'activePage' => 'file-management',
            'title' => 'File Management',
            'files' => [[
                'id' => 12,
                'filename' => 'IMG_1200.JPG',
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
                'filename' => 'IMG_1201.JPG',
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
            'folderBreadcrumbs' => [],
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
                    'folder_path' => 'Root Library / Dal_Uy / Pictures & Events',
                    'location_url' => site_url('workspace/file-management?folder=7&locate=10'),
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
        ];
        $html = view('workspace/media', $data);

        $this->assertStringNotContainsString('Visual duplicate', $html);
        $this->assertStringNotContainsString('data-duplicate-select value=', $html);
        $this->assertStringNotContainsString('data-groups-url=', $html);
        $this->assertStringContainsString('/preview?thumbnail=1', $html);
        $this->assertStringNotContainsString('data-bs-target="#duplicatesModal"', $html);
        $this->assertStringNotContainsString('id="restoreSelectedDuplicates"', $html);
        $this->assertStringNotContainsString('workspace/file-management/duplicates/restore', $html);
        $this->assertStringNotContainsString("input.name='duplicate_ids[]'", $html);
        $this->assertStringNotContainsString('Export Duplicate Report', $html);
        $this->assertStringNotContainsString('Duplicate scan mode', $html);
        $this->assertStringNotContainsString('value="upload_batch"', $html);
        $this->assertStringNotContainsString('value="media_library" checked', $html);
        $this->assertStringNotContainsString('Current upload', $html);
        $this->assertStringNotContainsString('id="automaticWatermarkToggle"', $html);
        $this->assertStringNotContainsString('Apply watermark automatically', $html);
        $this->assertStringContainsString('Uploads are stored as raw originals.', $html);
        $this->assertStringContainsString('workspace/file-management/upload/token', $html);
        $this->assertStringContainsString('await refreshUploadToken()', $html);
        $this->assertStringContainsString('response.status === 403', $html);
        $this->assertStringContainsString('item.file.size > maxUploadBytes', $html);
        $this->assertStringContainsString('No fixed file-size limit', $html);
        $this->assertStringContainsString('assets/js/chunk-uploads.js', $html);
        $this->assertStringContainsString('window.GreyhoundChunkUploads.send', $html);
        $this->assertStringNotContainsString('workspace/file-management/watermark-auto', $html);
        $this->assertStringContainsString('3 files &middot; 2 folders', $html);
        $this->assertStringContainsString('<span>Contents</span>', $html);
        $this->assertStringNotContainsString('<span class="text-center">Actions</span>', $html);
        $this->assertStringNotContainsString('<th class="text-end">Actions</th>', $html);
        $this->assertStringNotContainsString('<td class="text-end text-nowrap">', $html);
        $this->assertStringContainsString('Pillar / 2026 / ID Pictures', $html);
        $this->assertStringContainsString('workspace/file-management?folder=7', $html);
        $this->assertStringContainsString('Root Library', $html);
        $this->assertStringContainsString('aria-label="Current library folder"', $html);
        $this->assertStringNotContainsString('>All folders</a>', $html);
        $this->assertStringNotContainsString('>Parent folder</a>', $html);
        $this->assertStringNotContainsString('aria-label="Folder actions"', $html);
        $this->assertSame(4, substr_count($html, 'class="file-location-link"'));
        $this->assertStringContainsString('Search filenames, folder names, original dates taken, event names, and tags.', $html);
        $this->assertStringContainsString('placeholder="Search filename, folder, date taken, event, or tag"', $html);
        $this->assertStringNotContainsString('filterVisibleLibraryItems', $html);
        $this->assertStringContainsString('id="mediaFilterForm"', $html);
        $this->assertStringContainsString("allVisibleSelected ? 'Unselect all' : 'Select all'", $html);
        $this->assertStringContainsString('var shouldSelect = !visibleIds.every', $html);
        $this->assertStringNotContainsString('id="clearMediaSelection"', $html);
        $this->assertStringContainsString('bulk-selection-count d-none', $html);
        $this->assertStringContainsString("selectionCount.classList.toggle('d-none', selectedCount === 0)", $html);
        $this->assertStringContainsString('data-ajax-live-search', $html);
        $this->assertStringContainsString('data-ajax-live-delay="400"', $html);
        $this->assertStringContainsString('data-ajax-search-status', $html);
        $this->assertStringContainsString('Results update automatically as you type.', $html);
        $this->assertStringNotContainsString('submitLiveSearch', $html);

        // Posting a shared title must leave the raw library labels distinguishable.
        foreach ($data['files'] as &$file) $file['display_title'] = 'Dal UY';
        unset($file);
        $data['locatedFileId'] = 12;
        $postedHtml = view('workspace/media', $data);
        foreach (['IMG_1200.JPG', 'IMG_1201.JPG'] as $filename) {
            $this->assertStringContainsString('<div class="file-name">' . $filename . '</div>', $postedHtml);
            $this->assertStringContainsString('<div class="grid-file-name" title="' . $filename . '">' . $filename . '</div>', $postedHtml);
            $this->assertStringContainsString('data-preview-name="' . $filename . '"', $postedHtml);
        }
        $this->assertStringNotContainsString('<div class="file-name">Dal UY</div>', $postedHtml);
        $this->assertSame(4, substr_count($postedHtml, 'data-post-title="' . esc('Dal UY', 'attr') . '"'));
        $this->assertSame('Dal UY', \App\Libraries\MediaTitle::forFile($data['files'][0]));
        $this->assertSame(2, substr_count($postedHtml, 'data-located-file tabindex="-1"'), 'Highlight the same file in both grid and list views.');
    }
}
