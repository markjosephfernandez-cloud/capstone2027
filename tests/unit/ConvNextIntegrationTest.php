<?php

use App\Services\EventDetectionService;
use App\Services\StorageService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\Test\CIUnitTestCase;
use Config\GreyhoundAi;
use Config\Services;

final class ConvNextIntegrationTest extends CIUnitTestCase
{
    private string $image;
    private array $payload;
    private EventDetectionService $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->image = tempnam(sys_get_temp_dir(), 'convnext-test-');
        file_put_contents($this->image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $storage = $this->createMock(StorageService::class);
        $storage->method('resolveDatabaseFile')->willReturn($this->image);
        Services::injectMock('storage', $storage);
        $http = $this->createMock(CURLRequest::class);
        $http->method('post')->willReturnCallback(fn () => (new Response(config('App')))->setJSON($this->payload));
        Services::injectMock('curlrequest', $http);
        $config = new class extends GreyhoundAi {
            public function eventModelEndpoint(): string { return 'http://127.0.0.1:5001/predict'; }
        };
        $this->detector = new EventDetectionService($config);
    }

    protected function tearDown(): void
    {
        unlink($this->image);
        parent::tearDown();
    }

    public function testServerUploadUsesTheSameModelAndReviewThreshold(): void
    {
        foreach ([[79.99,true],[80.0,false]] as [$confidence,$review]) {
            $this->payload = ['event_name'=>'EXPO Activities','confidence_score'=>(float)$confidence,'model_version'=>'convnext'];
            $result = $this->detector->analyzeFile($this->image,'photo.jpg','Events');
            $this->assertSame('EXPO Activities',$result['classification']);
            $this->assertSame($review,$result['needs_review']);
            $this->assertSame('convnext',$result['detector_version']);
        }
        $this->payload = ['invalid'=>true];
        $result = $this->detector->analyzeFile($this->image,'expo.jpg','Events');
        $this->assertSame(0.0,$result['confidence_score']);
        $this->assertTrue($result['needs_review']);
    }

    public function testQueuedUploadDoesNotCompleteWithAFilenameFallbackWhenAiIsOffline(): void
    {
        $http = $this->createMock(CURLRequest::class);
        $http->method('post')->willThrowException(new RuntimeException('Connection refused'));
        Services::injectMock('curlrequest', $http);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ConvNeXt image analysis is unavailable');
        $this->detector->classifyAndStore(['filename'=>'graduation.jpg','filepath'=>$this->image], true);
    }

    public function testQueuedUploadRejectsAnotherModel(): void
    {
        $this->payload = ['event_name'=>'EXPO Activities','confidence_score'=>95,'model_version'=>'another-model'];
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ConvNeXt image analysis is unavailable');
        $this->detector->classifyAndStore(['filename'=>'photo.jpg','filepath'=>$this->image], true);
    }

    public function testAllEightMappedClassesAreAccepted(): void
    {
        $method = new ReflectionMethod(EventDetectionService::class, 'trainedModelResult');
        $events = ['EXPO Activities', 'Graduation Activities', 'Kasadyaan Celebrations', 'Kasadyaan: Hara', 'Kasadyaan: Parada', 'Kasadyaan: Pasundayag', 'Dal-uy Events', 'Intramurals'];
        foreach ($events as $event) {
            $this->payload = ['event_name' => $event, 'confidence_score' => 95.5, 'model_version' => 'greyhound-hub-2.0-convnext'];
            $result = $method->invoke($this->detector, ['filepath' => $this->image]);
            $this->assertSame($event, $result['event_name']);
            $this->assertSame('greyhound-hub-2.0-convnext', $result['detector_version']);
        }
    }

    public function testMalformedResponsesAreRejected(): void
    {
        $method = new ReflectionMethod(EventDetectionService::class, 'trainedModelResult');
        foreach ([['confidence_score' => 50], ['event_name' => 'Unknown', 'confidence_score' => 90], ['event_name' => null, 'confidence_score' => 'invalid'], ['event_name' => null, 'confidence_score' => 101]] as $payload) {
            $this->payload = $payload;
            $this->assertNull($method->invoke($this->detector, ['filepath' => $this->image]));
        }
    }

    public function testLowConfidenceDoesNotBecomeAKeywordGuess(): void
    {
        $db = db_connect();
        $this->assertSame('SQLite3', $db->DBDriver);
        $this->assertSame(':memory:', $db->database);
        foreach (['files', 'tag', 'ai_event_detections'] as $table) $db->query('DROP TABLE IF EXISTS ' . $db->prefixTable($table));
        $db->query('CREATE TABLE ' . $db->prefixTable('files') . ' (id INTEGER PRIMARY KEY, classification TEXT, detected_objects TEXT, tags TEXT, last_scanned TEXT)');
        $db->query('CREATE TABLE ' . $db->prefixTable('tag') . ' (tag_id INTEGER PRIMARY KEY, tag TEXT, type TEXT, filepath TEXT, file_id INTEGER)');
        $db->query('CREATE TABLE ' . $db->prefixTable('ai_event_detections') . ' (id INTEGER PRIMARY KEY, media_id INTEGER, event_name TEXT, confidence_score REAL, detector_version TEXT, evidence_json TEXT, needs_review INTEGER, processed_at TEXT)');
        try {
            $db->table('files')->insert(['id' => 1]);
            $this->payload = ['event_name' => null, 'confidence_score' => 45.2, 'model_version' => 'greyhound-hub-2.0-convnext', 'evidence' => [['type' => 'image_classifier', 'value' => 'intramurals']]];
            $result = $this->detector->classifyAndStore(['id' => 1, 'filepath' => $this->image, 'filename' => 'graduation.jpg', 'filetype' => 'jpg'], true);
            $this->assertNull($result['event_name']);
            $this->assertTrue($result['needs_review']);
            $this->assertSame(45.2, $result['confidence_score']);
            $this->assertSame('greyhound-hub-2.0-convnext', $result['detector_version']);
            $stored = $db->table('ai_event_detections')->where('media_id', 1)->get()->getRowArray();
            $this->assertNull($stored['event_name']);
            $this->assertSame(1, (int) $stored['needs_review']);
            $this->assertStringContainsString('intramurals', $stored['evidence_json']);
            $this->payload = ['event_name' => 'Intramurals', 'confidence_score' => 80, 'model_version' => 'greyhound-hub-2.0-convnext'];
            $result = $this->detector->classifyAndStore(['id' => 1, 'filepath' => $this->image, 'filename' => 'sports.jpg', 'filetype' => 'jpg']);
            $this->assertFalse($result['needs_review'], 'Exactly 80% passes the confidence gate.');
            $this->assertSame(0, (int) $db->table('ai_event_detections')->where('media_id', 1)->get()->getRowArray()['needs_review']);
            // An unavailable/invalid model response must not manufacture AI confidence.
            $this->payload = [];
            $result = $this->detector->classifyAndStore(['id' => 1, 'filepath' => $this->image, 'filename' => 'graduation.jpg', 'filetype' => 'jpg']);
            $this->assertTrue($result['needs_review']);
            $this->assertSame(0.0, $result['confidence_score']);
        } finally {
            foreach (['files', 'tag', 'ai_event_detections'] as $table) $db->query('DROP TABLE ' . $db->prefixTable($table));
        }
    }
}
