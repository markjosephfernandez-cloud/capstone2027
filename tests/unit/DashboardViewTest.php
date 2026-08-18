<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class DashboardViewTest extends CIUnitTestCase
{
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
            ],
            'categories' => [],
            'uploadTrend' => $uploadTrend,
            'activity' => [],
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

        $trendPosition = strpos($html, 'Media Upload Trend');
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
