<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class DashboardViewTest extends CIUnitTestCase
{
    public function testDisconnectedStorageDoesNotPreventDashboardStatistics(): void
    {
        $storage = $this->createMock(\App\Services\StorageService::class);
        $storage->expects($this->once())->method('root')
            ->willThrowException(new RuntimeException('Server disconnected. Connect the server share and refresh.'));
        \Config\Services::injectMock('storage', $storage);
        $controller = new class extends \App\Controllers\Web\DashboardController {
            public function readStorageStats(): array
            {
                return $this->storageStats(['role' => 'admin'], 300);
            }
        };
        try {
            $stats = $controller->readStorageStats();
            $this->assertFalse($stats['storage_available']);
            $this->assertSame(0, $stats['disk_total']);
        } finally {
            \Config\Services::resetSingle('storage');
        }
    }

    public function testDashboardRendersMediaUploadTrendAboveStorageAnalytics(): void
    {
        helper('dashboard');
        $start = new DateTimeImmutable('2026-07-14');
        $uploadTrend = [];
        for ($day = 0; $day < 30; $day++) {
            $date = $start->modify('+' . $day . ' days');
            $uploadTrend[] = [
                'date'  => $date->format('Y-m-d'),
                'label' => $date->format('M j'),
                'count' => $day === 29 ? 3 : 0,
            ];
        }

        $html = view('dashboard', [
            'user'            => ['employee_id' => 0, 'name' => 'Test User', 'role' => 'admin'],
            'activePage'      => 'dashboard',
            'isAdmin'        => false,
            'error'          => null,
            'success'        => null,
            'consentRequired' => false,
            'stats'           => [
                'total_files' => 3,
                'active_files' => 3,
                'total_bytes' => 300,
                'disk_total' => 1000,
                'disk_free' => 700,
                'disk_used' => 300,
                'storage_available' => false,
            ],
            'categories' => [],
            'uploadTrend' => $uploadTrend,
            'activity' => [
                ['id'=>73, 'user_name'=>'Mark Anthony Besario', 'action'=>'SERVER_LIBRARY_TAGS', 'description'=>"Server Library tags: Sinco's/D5/12X18.jpg, Sinco's/D5/1C6A0508.jpg", 'created_at'=>'2026-10-06 12:00:00'],
                ['id'=>74, 'user_name'=>'Test User', 'action'=>'LOGIN', 'description'=>'Signed in.', 'created_at'=>'2026-10-06 12:01:00'],
                ['id'=>75, 'user_name'=>'Test User', 'action'=>'LOGOUT', 'description'=>'Signed out.', 'created_at'=>'2026-10-06 12:02:00'],
            ],
            'canManageDistribution' => true,
            'departmentRequests' => [[
                'id' => 42,
                'department' => 'College of Computing Studies',
                'title' => 'Foundation Week photos',
                'status' => 'submitted',
                'submitted_at' => '2026-08-12 10:00:00',
                'requester_name' => 'Department User',
            ]],
        ]);

        $this->assertStringContainsString('Server storage is unavailable.', $html);
        $this->assertSame(2, substr_count($html, '>Unavailable</h6>'));
        $this->assertStringNotContainsString('of total storage', $html);
        $this->assertStringContainsString('?log=73#audit-log-73', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
        $this->assertSame(1, substr_count($html, '>View details'));
        $this->assertStringContainsString('Server Library tags:', $html);
        $this->assertStringNotContainsString('12X18.jpg', $html);
        $this->assertStringNotContainsString('1C6A0508.jpg', $html);
        $decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5);
        $this->assertStringNotContainsString('?log=74', $decoded);
        $this->assertStringNotContainsString('?log=75', $decoded);
        $this->assertStringContainsString('Signed in.', $html);
        $this->assertStringContainsString('Signed out.', $html);

        $trendPosition = strpos($html, 'Media Uploads');
        $storagePosition = strpos($html, 'Storage Analytics');

        $this->assertNotFalse($trendPosition);
        $this->assertNotFalse($storagePosition);
        $this->assertLessThan($storagePosition, $trendPosition);
        $this->assertMatchesRegularExpression('/3\s*uploads in this period/', preg_replace('/\s+/', ' ', strip_tags($html)));
        $this->assertStringContainsString('<polyline class="upload-trend-line"', $html);
        $this->assertStringContainsString('Department Requests', $html);
        $this->assertStringContainsString('?tab=requests&amp;request=42#request-42', $html);
        $this->assertGreaterThan(strpos($html, 'Recent Activity'), strpos($html, 'Department Requests'));
    }
}
