<?php

use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class DistributionTransactionLifecycleTest extends CIUnitTestCase
{
    public function testLegacyZeroCycleHistoryGetsChronologicalDisplayCycles(): void
    {
        $controller = (new ReflectionClass(\App\Controllers\Web\DistributionController::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod($controller, 'withDisplayCycles');
        $history = $method->invoke($controller, [
            ['action' => 'original', 'cycle_number' => 1],
            ['action' => 'marked_incomplete', 'cycle_number' => 0],
            ['action' => 'transmitted', 'cycle_number' => 0],
            ['action' => 'submitted', 'cycle_number' => 0],
        ]);

        $this->assertSame(2, $history[0]['display_cycle']);
        $this->assertSame('resubmission', $history[0]['display_action']);
        $this->assertSame(1, $history[1]['display_cycle']);
        $this->assertSame(1, $history[2]['display_cycle']);
        $this->assertNull($history[3]['display_cycle']);
    }

    public function testLifecycleUsesSeparateStatusAuthorizationAndRequiredFeedback(): void
    {
        $service = file_get_contents(APPPATH . 'Services/DistributionService.php');
        $controller = file_get_contents(APPPATH . 'Controllers/Web/DistributionController.php');
        $workspace = file_get_contents(APPPATH . 'Controllers/Web/WorkspaceController.php');
        $api = file_get_contents(APPPATH . 'Controllers/Api/FileController.php');

        $this->assertStringContainsString("'status' => 'pending', 'authorization_status' => 'not_authorized'", $service);
        $this->assertStringContainsString("'status' => 'pending_incomplete', 'authorization_status' => 'not_authorized'", $service);
        $this->assertStringContainsString("'status' => 'complete', 'authorization_status' => 'authorized'", $service);
        $this->assertStringContainsString('Feedback is required when marking a transaction as incomplete.', $service);
        $this->assertStringContainsString('Completion feedback is required before confirming this transaction as complete.', $service);
        $this->assertStringContainsString("(string) \$request['status'] !== 'pending'", $service);
        $this->assertStringContainsString("'department_files'", $workspace);
        $this->assertStringContainsString("'department_files'", $api);
        $this->assertStringContainsString('public function reviewPreview', $controller);
    }

    public function testReviewInterfaceExposesBothDepartmentDecisionsAndHistory(): void
    {
        $view = file_get_contents(APPPATH . 'Views/distribution/index.php');
        $this->assertStringContainsString('Mark as Incomplete', $view);
        $this->assertStringContainsString('Confirm Complete', $view);
        $this->assertStringContainsString('Completion Feedback *', $view);
        $this->assertStringContainsString('Transaction history', $view);
        $this->assertStringContainsString('Not Authorized — Not yet available in Department File Management', $view);
        $this->assertStringContainsString('Authorized — Available in Department File Management', $view);
        $this->assertStringContainsString('Send corrections', $view);
        $this->assertStringContainsString('data-authorization=', $view);
        $this->assertStringContainsString("normalizedAuthorization === 'authorized'", $view);
        $this->assertStringContainsString("normalizedStatus === 'complete'", $view);
        $this->assertStringContainsString("entry.display_cycle ? 'Cycle ' + entry.display_cycle : 'Request'", $view);
        $this->assertStringNotContainsString("h(entry.cycle_number || 0)", $view);
    }
}
