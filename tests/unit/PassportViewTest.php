<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class PassportViewTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Views retain data by default; another page's pagination is not input here.
        service('renderer')->resetData();
    }

    public function testPassportPageRendersWithSharedApplicationLayout(): void
    {
        $html = view('addons/passports', [
            'user'       => ['employee_id' => 0, 'name' => 'Test User', 'role' => 'admin'],
            'activePage' => 'passports',
            'title'      => 'Metadata Passports',
            'passports'  => [],
            'summary'    => ['total' => 0, 'ai_flags' => 0],
            'isAdmin'    => false,
            'success'    => null,
            'error'      => null,
        ]);

        $this->assertStringContainsString('<title>Metadata Passports</title>', $html);
        $this->assertStringContainsString('<main id="main" class="main">', $html);
        $this->assertStringContainsString('No passports have been generated yet.', $html);
    }

    public function testPassportDetailsModalRendersPhotoBesideMetadata(): void
    {
        $passport = [
            'file_id'              => 42,
            'display_title'        => 'Graduation portrait',
            'filename'             => 'portrait.jpg',
            'filetype'             => 'jpg',
            'mime_type'            => 'image/jpeg',
            'size'                 => 5242880,
            'passport_uid'         => 'FU-PSP-2026-000042',
            'event_name'           => 'Graduation Activities',
            'department'           => 'Creative Department',
            'photographer'         => 'Campus Photographer',
            'uploader_name'        => 'Test Admin',
            'uploaded_by'          => '20230107',
            'date_taken'           => '2023-01-07 10:15:00',
            'upload_date'          => '2026-08-13 08:53:57',
            'ai_classification'    => 'Graduation Activities',
            'confidence_score'     => 99.52,
            'ai_version'           => 'greyhound-hub-2.0-resnet50',
            'processing_time_ms'   => 481,
            'needs_review'         => 0,
            'copyright_owner'      => 'Foundation University',
            'distribution_rights'  => 'Internal Use Only',
            'usage_restrictions'   => 'Automatically published after AI processing.',
            'digital_signature'    => 'sha256-test-signature',
            'c2pa_compliant'       => 0,
        ];

        $html = view('addons/passports', [
            'user'       => ['employee_id' => 20230107, 'name' => 'Test Admin', 'role' => 'admin'],
            'activePage' => 'passports',
            'title'      => 'Metadata Passports',
            'passports'  => [$passport],
            'summary'    => ['total' => 1, 'ai_flags' => 0],
            'isAdmin'    => true,
            'success'    => null,
            'error'      => null,
        ]);

        $this->assertStringContainsString('data-bs-target="#passportDetailsModal"', $html);
        $this->assertStringContainsString('Original date taken', $html);
        $this->assertStringContainsString('data-detail-field="digital_signature"', $html);
        $this->assertStringContainsString('Download original', $html);

        $photoPosition = strpos($html, 'class="passport-details-photo"');
        $detailsPosition = strpos($html, 'class="passport-details-panel"');
        $this->assertNotFalse($photoPosition);
        $this->assertNotFalse($detailsPosition);
        $this->assertLessThan($detailsPosition, $photoPosition);
    }

    public function testPassportSearchAndPaginationPreserveTheFilter(): void
    {
        $html = view('addons/passports', [
            'user'        => ['employee_id' => 0, 'name' => 'Test User', 'role' => 'admin'],
            'activePage'  => 'passports',
            'title'       => 'Metadata Passports',
            'passports'   => [],
            'summary'     => ['total' => 41, 'ai_flags' => 0],
            'searchQuery' => 'Graduation 2023',
            'pagination'  => [
                'current_page' => 2,
                'total_pages'  => 3,
                'total_results'=> 41,
                'per_page'     => 20,
                'from'         => 21,
                'to'           => 40,
            ],
            'isAdmin'     => false,
            'success'     => null,
            'error'       => null,
        ]);

        $this->assertStringContainsString('name="q" value="Graduation 2023"', $html);
        $this->assertStringContainsString('Search filename, original date taken, or event', $html);
        $this->assertStringContainsString('Showing 21–40 of 41 passports', $html);
        $this->assertStringContainsString('q=Graduation+2023', $html);
        $this->assertStringContainsString('aria-label="Previous page"', $html);
        $this->assertStringContainsString('aria-label="Next page"', $html);
    }
}
