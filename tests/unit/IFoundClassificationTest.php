<?php

use App\Services\IFoundClassificationService;
use CodeIgniter\Test\CIUnitTestCase;

final class IFoundClassificationTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $this->assertSame('SQLite3', $db->DBDriver);
        $this->assertSame(':memory:', $db->database);
        foreach (['files', 'ai_event_detections'] as $table) $db->query('DROP TABLE IF EXISTS ' . $db->prefixTable($table));
        $db->query('CREATE TABLE ' . $db->prefixTable('files') . ' (id INTEGER PRIMARY KEY, folder_id INTEGER, filetype TEXT, mime_type TEXT, is_deleted INTEGER DEFAULT 0)');
        $db->query('CREATE TABLE ' . $db->prefixTable('ai_event_detections') . ' (media_id INTEGER PRIMARY KEY, event_name TEXT, confidence_score REAL, detector_version TEXT, evidence_json TEXT, reviewed_at TEXT, needs_review INTEGER DEFAULT 1)');
    }

    protected function tearDown(): void
    {
        foreach (['files', 'ai_event_detections'] as $table) db_connect()->query('DROP TABLE IF EXISTS ' . db_connect()->prefixTable($table));
        parent::tearDown();
    }

    private function add(int $id, ?int $folder, ?string $event, ?float $confidence, array $file = [], array $detection = []): void
    {
        db_connect()->table('files')->insert(array_merge(['id' => $id, 'folder_id' => $folder, 'filetype' => 'jpg', 'mime_type' => 'image/jpeg'], $file));
        db_connect()->table('ai_event_detections')->insert(array_merge(['media_id' => $id, 'event_name' => $event, 'confidence_score' => $confidence, 'detector_version' => 'greyhound-hub-2.0-convnext', 'evidence_json' => '[]'], $detection));
    }

    private function results(array $ids): array
    {
        $visible = db_connect()->table('files f')->select('f.*, ed.*')->join('ai_event_detections ed', 'ed.media_id = f.id')->whereIn('f.id', $ids)->orderBy('f.id')->get()->getResultArray();
        return array_column((new IFoundClassificationService())->annotate($visible), null, 'id');
    }

    public function testThresholdAndDominanceAreIndependentOfSearchAndPagination(): void
    {
        $this->add(1, 10, 'Graduation Activities', 80);
        $this->add(2, 10, 'Graduation Activities', 90);
        $this->add(3, 10, 'EXPO Activities', 95);
        foreach ([4, 5, 6] as $id) $this->add($id, 10, 'EXPO Activities', 79.99);
        $result = $this->results([1, 3, 4]);
        $this->assertSame('dominant', $result[1]['ai_result']);
        $this->assertSame('different', $result[3]['ai_result']);
        $this->assertSame('needs_review', $result[4]['ai_result']);
        $this->assertSame('Graduation Activities', $result[3]['dominant_event']);
        // The searched page has only the outlier; hidden folder peers still vote.
        $this->assertSame('different', $this->results([3])[3]['ai_result']);
    }

    public function testResultFilterKeepsFolderDominanceAndFiltersBeforePagination(): void
    {
        $this->add(1, 10, 'Graduation Activities', 90);
        $this->add(2, 10, 'Graduation Activities', 95);
        $this->add(3, 10, 'EXPO Activities', 99);
        $this->add(4, 10, 'EXPO Activities', 50);
        $this->add(5, 10, 'Graduation Activities', 90, [], ['reviewed_at' => '2026-10-14 10:00:00', 'needs_review' => 0]);
        $candidates = db_connect()->table('files f')->select('f.*, ed.*')
            ->join('ai_event_detections ed', 'ed.media_id = f.id')->orderBy('f.id', 'DESC')->get()->getResultArray();
        $service = new IFoundClassificationService();
        foreach (['needs_review' => [4], 'different' => [3], 'dominant' => [2, 1], 'reviewed' => [5], 'not_applicable' => []] as $filter => $ids) {
            $filtered = $service->rankedResults($candidates, $filter);
            $this->assertSame($ids, array_map('intval', array_column($filtered, 'id')));
            foreach ($filtered as $file) $this->assertSame('Graduation Activities', $file['dominant_event']);
        }
        $this->assertCount(5, $service->rankedResults($candidates));
        $this->assertSame([1], array_map('intval', array_column(array_slice($service->rankedResults($candidates, 'dominant'), 1, 1), 'id')));
    }

    public function testOtherFoldersDeletedFilesAndVideosDoNotChangeDominance(): void
    {
        $this->add(1, 10, 'Graduation Activities', 90);
        foreach ([2, 3, 4] as $id) $this->add($id, 20, 'EXPO Activities', 99);
        foreach ([5, 6] as $id) $this->add($id, 10, 'EXPO Activities', 99, ['is_deleted' => 1]);
        foreach ([7, 8] as $id) $this->add($id, 10, 'EXPO Activities', 99, ['filetype' => 'mp4', 'mime_type' => 'video/mp4']);
        $result = $this->results([1, 2, 7]);
        $this->assertSame('Graduation Activities', $result[1]['dominant_event']);
        $this->assertSame('EXPO Activities', $result[2]['dominant_event']);
        $this->assertSame('not_applicable', $result[7]['ai_result']);
    }

    public function testTiesAndUnclassifiedGroupsNeedReview(): void
    {
        $this->add(1, 10, 'Graduation Activities', 99);
        $this->add(2, 10, 'EXPO Activities', 80);
        $this->add(3, 20, null, 50);
        $this->add(4, 20, 'Intramurals', null);
        $result = $this->results([1, 2, 3, 4]);
        $this->assertTrue($result[1]['dominant_tied']);
        foreach ($result as $file) {
            $this->assertNull($file['dominant_event']);
            $this->assertSame('needs_review', $file['ai_result']);
        }
    }

    public function testOriginalPredictionSurvivesHumanCorrection(): void
    {
        $this->add(1, 10, 'Graduation Activities', 85);
        $this->add(2, 10, 'EXPO Activities', 95, [], ['evidence_json' => json_encode([['type' => 'image_classifier', 'value' => 'Graduation']]), 'reviewed_at' => '2026-09-26 10:00:00', 'needs_review' => 0]);
        $result = $this->results([2])[2];
        $this->assertSame('Graduation Activities', $result['ai_prediction']);
        $this->assertSame('Graduation Activities', $result['dominant_event']);
        $this->assertSame('reviewed', $result['ai_result']);
        $this->assertSame('EXPO Activities', $result['event_name']);
    }

    public function testLowConfidenceReviewUpdatesTheResultAndItsSortPosition(): void
    {
        $this->add(1, 10, 'EXPO Activities', 95);
        $this->add(2, 10, 'EXPO Activities', 79.66, [], ['evidence_json' => '[{"type":"image_classifier","value":"intramurals"}]']);
        $this->add(3, 10, 'Graduation Activities', 90);
        $this->add(4, 10, 'EXPO Activities', 92);
        $this->add(5, 10, 'EXPO Activities', 99, [], ['reviewed_at' => '2026-09-26 10:00:00', 'needs_review' => 0]);
        $this->assertSame('reviewed', $this->results([5])[5]['ai_result']);
        $this->assertSame('needs_review', $this->results([2])[2]['ai_result']);
        db_connect()->table('ai_event_detections')->where('media_id', 2)->update(['reviewed_at' => '2026-09-26 10:00:00', 'needs_review' => 0]);
        $result = $this->results([2])[2];
        $this->assertSame('reviewed', $result['ai_result']);
        $this->assertTrue($result['result_from_review']);
        $this->assertSame('Intramurals', $result['ai_prediction']);
        $this->assertSame(79.66, (float) $result['confidence_score']);
        $candidates = db_connect()->table('files f')->select('f.*, ed.*')->join('ai_event_detections ed', 'ed.media_id = f.id')->whereIn('f.id', [2, 3])->orderBy('f.id')->get()->getResultArray();
        $sorted = (new IFoundClassificationService())->reviewPage($candidates, 20, 0);
        $this->assertSame([3, 2], array_map('intval', array_column($sorted, 'id')));
    }

    public function testCompletedReviewWithoutComparisonDoesNotAskForReviewAgain(): void
    {
        $this->add(1, 10, null, 40, [], ['reviewed_at' => '2026-09-26 10:00:00', 'needs_review' => 0]);
        $this->add(2, 20, 'EXPO Activities', 70, [], ['reviewed_at' => '2026-09-26 10:00:00', 'needs_review' => 0]);
        $results = $this->results([1, 2]);
        $this->assertSame('reviewed', $results[1]['ai_result']);
        $this->assertSame('reviewed', $results[2]['ai_result']);
        db_connect()->table('ai_event_detections')->where('media_id', 2)->update(['needs_review' => 1]);
        $this->assertSame('needs_review', $this->results([2])[2]['ai_result']);
    }

    public function testKeywordGuessesCannotVoteEvenWithLegacyHighConfidence(): void
    {
        $this->add(1, null, 'Graduation Activities', 85);
        $this->add(2, null, 'EXPO Activities', 98, [], ['detector_version' => 'greyhound-keyword-baseline']);
        $this->add(3, null, 'EXPO Activities', 98, [], ['evidence_json' => '[{"type":"keyword","value":"expo"}]']);
        $result = $this->results([1, 2, 3]);
        $this->assertSame('Graduation Activities', $result[1]['dominant_event']);
        $this->assertSame('needs_review', $result[2]['ai_result']);
        $this->assertSame('needs_review', $result[3]['ai_result']);
        $this->assertSame([], (new IFoundClassificationService())->annotate([]));
    }

    public function testReviewOrderAppliesBeforePaginationAndPreservesOrderWithinGroups(): void
    {
        // Newer dominant images must not push older review items to later pages.
        foreach (range(1, 22) as $id) $this->add($id, 10, 'Graduation Activities', 95);
        $this->add(23, 10, 'EXPO Activities', 90);
        $this->add(24, 10, 'EXPO Activities', 50);
        $this->add(25, 10, null, null);
        $this->add(26, 10, null, null, ['filetype' => 'mp4', 'mime_type' => 'video/mp4']);
        $candidates = db_connect()->table('files f')->select('f.*, ed.*')->join('ai_event_detections ed', 'ed.media_id = f.id')->orderBy('f.id')->get()->getResultArray();
        $service = new IFoundClassificationService();
        $first = $service->reviewPage($candidates, 20, 0);
        $second = $service->reviewPage($candidates, 20, 20);
        $this->assertSame([24, 25, 23], array_map('intval', array_column(array_slice($first, 0, 3), 'id')));
        $this->assertSame(['needs_review', 'needs_review', 'different'], array_column(array_slice($first, 0, 3), 'ai_result'));
        $this->assertSame(range(1, 17), array_map('intval', array_column(array_slice($first, 3), 'id')));
        $this->assertSame([18, 19, 20, 21, 22, 26], array_map('intval', array_column($second, 'id')));
        $this->assertSame('not_applicable', $second[5]['ai_result']);
        $this->assertSame([], $service->reviewPage([], 20, 0));
    }
}
