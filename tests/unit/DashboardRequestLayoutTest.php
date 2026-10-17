<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class DashboardRequestLayoutTest extends CIUnitTestCase
{
    public function testDepartmentRequestRowsConstrainStatusInsideCard(): void
    {
        $view = file_get_contents(APPPATH . 'Views/dashboard.php');

        $this->assertIsString($view);
        $this->assertStringContainsString('grid-template-columns: minmax(0, 1fr) auto', $view);
        $this->assertStringContainsString('class="dashboard-request-details"', $view);
        $this->assertStringContainsString('badge dashboard-request-status', $view);
        $this->assertStringNotContainsString('dashboard-request-meta text-truncate', $view);
    }
}
