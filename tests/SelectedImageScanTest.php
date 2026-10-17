<?php
use App\Services\SelectedImageScan;
use App\Services\ServerLibraryService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Storage;

final class SelectedImageScanTest extends CIUnitTestCase
{
    public function testRejectsMissingAndUnknownModes(): void
    {
        foreach ([[],null,'objects',['unknown'],[['events']],['duplicates'],['objects','duplicates']] as $invalid) {
            try { SelectedImageScan::validateModes($invalid); $this->fail('Invalid option accepted'); }
            catch (RuntimeException) { $this->addToAssertionCount(1); }
        }
        $this->assertSame(['objects','expressions'],SelectedImageScan::validateModes(['objects','expressions']));
    }

    public function testCompletionUsesSuccessfulResultsAndPreservesReviews(): void
    {
        $this->assertSame([], SelectedImageScan::completedFromResults([], null, []));
        $this->assertSame(['events'], SelectedImageScan::completedFromResults(['last_scanned'=>'2026-10-15'], null, []));
        $this->assertSame([], SelectedImageScan::completedFromResults(['last_scanned'=>'2026-10-15'], ['processed_at'=>'2026-10-15','detector_version'=>'greyhound-keyword-baseline'], []));
        $this->assertSame(['events'], SelectedImageScan::completedFromResults([], ['processed_at'=>'2026-10-15','detector_version'=>'convnext-v1'], []));
        $this->assertSame(['events','objects','expressions'], SelectedImageScan::completedFromResults([], ['reviewed_at'=>'2026-10-15'], ['objects'=>['status'=>'complete','items'=>[]], 'expressions'=>['status'=>'no_face','items'=>[]]]));
        foreach (['failed','unavailable','disabled','not_requested'] as $status) {
            $this->assertSame([], SelectedImageScan::completedFromResults([], null, ['objects'=>['status'=>$status], 'expressions'=>['status'=>$status]]));
        }
    }

    public function testEachImageRunsOnlyMissingAnalysesAndRetriesFailures(): void
    {
        $library = $this->createMock(ServerLibraryService::class);
        $library->method('resolve')->willReturn('/selected/photo.png');
        $scan = new class($library) extends SelectedImageScan {
            public array $completed = [], $visual = [];
            public int $events = 0;
            public bool $eventOffline = false, $visualOffline = false;
            protected function fileFor(string $path, int $employee): array { return ['id'=>7]; }
            protected function completedTasks(array $file): array { return $this->completed; }
            protected function detectEvent(array $file): void {
                $this->events++;
                if ($this->eventOffline) throw new RuntimeException('Offline');
                $this->completed[] = 'events';
            }
            protected function analyzeVisual(array $file, array $tasks): array {
                $this->visual[] = $tasks;
                $results = [];
                foreach ($tasks as $task) {
                    $results[$task] = ['status'=>$this->visualOffline ? 'unavailable' : 'complete'];
                    if (!$this->visualOffline) $this->completed[] = $task;
                }
                return $results;
            }
        };
        $entries = [];
        $run = fn () => $scan->process('photo.png', 7, SelectedImageScan::TASKS, $entries);
        $this->assertSame('imported', $run()['status']);
        $this->assertSame(1, $scan->events);
        $this->assertSame([['objects','expressions']], $scan->visual);
        $this->assertSame('skipped', $run()['status']);
        $this->assertSame(1, $scan->events);
        $this->assertCount(1, $scan->visual);
        $scan->completed = ['events'];
        $this->assertSame('imported', $run()['status']);
        $this->assertSame(1, $scan->events);
        $scan->completed = ['events', 'objects'];
        $scan->visualOffline = true;
        $this->assertSame('failed', $run()['status']);
        $this->assertSame(['expressions'], end($scan->visual));
        $scan->visualOffline = false;
        $this->assertSame('imported', $run()['status']);
        $this->assertSame(['expressions'], end($scan->visual));
        $scan->completed = [];
        $scan->eventOffline = true;
        $this->assertSame('failed', $run()['status']);
        $this->assertSame(['objects','expressions'], $scan->completed);
        $scan->eventOffline = false;
        $count = count($scan->visual);
        $this->assertSame('imported', $run()['status']);
        $this->assertCount($count, $scan->visual);
    }
}
