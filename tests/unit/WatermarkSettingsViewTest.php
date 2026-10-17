<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class WatermarkSettingsViewTest extends CIUnitTestCase
{
    private function renderPage(array $overrides = []): string
    {
        return view('addons/watermarking', array_replace([
            'user' => ['employee_id' => 1, 'name' => 'Creative Admin', 'role' => 'admin'],
            'activePage' => 'watermarking',
            'title' => 'Watermarking',
            'settings' => ['enabled' => 1, 'position' => 'bottom_right', 'opacity' => 65, 'resize_percent' => 100],
            'logos' => [['code' => 'FU', 'label' => 'Foundation University']],
            'defaultLogoCode' => 'FU',
            'images' => [],
            'searchQuery' => '',
            'type' => '',
            'folderId' => null,
            'folders' => [],
            'selectedFolder' => null,
            'parentFolder' => null,
            'folderBreadcrumbs' => [],
            'selectedFolderPath' => 'Root Library',
            'isAdmin' => true,
            'success' => null,
            'error' => null,
        ], $overrides));
    }

    public function testWatermarkPageUsesTemporaryFrontendPreviewAndRawOriginals(): void
    {
        $html = $this->renderPage();
        $this->assertStringContainsString('Originals are never changed.', $html);
        $this->assertStringContainsString('Image Library', $html);
        $this->assertStringNotContainsString('Save logo', $html);
        $this->assertStringContainsString('Configure preview', $html);
        $this->assertStringContainsString('data-bs-target="#watermarkPreviewModal"', $html);
        $this->assertStringContainsString('class="preview-control-panel"', $html);
        $this->assertStringContainsString('id="previewScale" type="range" min="10" max="200" step="1"', $html);
        $this->assertStringContainsString('Root Library', $html);
        $this->assertStringNotContainsString('All folders', $html);
        $this->assertStringNotContainsString('Parent folder', $html);
        $this->assertStringContainsString('.folder-grid.is-list-view .folder-list-header', $html);
        $this->assertStringContainsString('id="applyLogo"', $html);
        $this->assertStringContainsString("allSelected ? 'Unselect all' : 'Select all'", $html);
        $this->assertStringContainsString('apply-logo-button d-none', $html);
        $this->assertStringContainsString('id="downloadSelected"', $html);
        $this->assertStringContainsString('<i class="bi bi-download"></i>', $html);
        $this->assertStringContainsString('if (getSelectedIds().size === 0)', $html);
        $this->assertStringContainsString("input.name = 'file_ids[]'", $html);
        $this->assertStringContainsString('data-applied-badge', $html);
        $this->assertStringContainsString('id="imagePreviewModal"', $html);
        $this->assertSame(2, substr_count($html, 'class="preview-canvas"'));
        $this->assertStringContainsString('.preview-canvas {', $html);
        $this->assertStringNotContainsString('>Watermarked</button>', $html);
        $this->assertStringNotContainsString('>Original</a>', $html);
        $this->assertStringContainsString('data-view="list"', $html);
        $this->assertStringContainsString('data-view="grid"', $html);
        $this->assertStringNotContainsString('Automatic watermark', $html);
        $this->assertStringNotContainsString('Apply default to existing images', $html);
    }

    public function testUploadFormIsOnlyAvailableToAdmins(): void
    {
        foreach (['admin', 'super_admin', 'staff', 'writer', 'department'] as $role) {
            $html = $this->renderPage(['user' => ['employee_id' => 0, 'name' => 'Test User', 'role' => $role]]);
            if (in_array($role, ['admin', 'super_admin'], true)) {
                $this->assertStringContainsString('id="watermarkLogoName"', $html);
                $this->assertStringContainsString('enctype="multipart/form-data"', $html);
                $this->assertStringContainsString('name="logo_file"', $html);
            } else {
                $this->assertStringNotContainsString('name="logo_file"', $html);
            }
        }
    }

    public function testUploadedLogoNameIsEscapedAndSelectionUsesItsStoredCode(): void
    {
        $code = 'CUSTOM-1234567890ABCDEF';
        $html = $this->renderPage([
            'logos' => [['code' => $code, 'label' => 'Alumni <Logo>', 'name' => 'Alumni <Logo>']],
            'defaultLogoCode' => $code,
        ]);
        $this->assertStringContainsString('Alumni &lt;Logo&gt;', $html);
        $this->assertStringNotContainsString('Alumni <Logo>', $html);
        $this->assertStringContainsString('/watermarking/logo/' . $code, $html);
        $this->assertMatchesRegularExpression('/value="' . $code . '"[^>]+checked/s', $html);
    }
}
