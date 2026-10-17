<?php

use App\Controllers\Web\WorkspaceController;
use App\Services\AuditService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

final class IFoundReviewTagsTest extends CIUnitTestCase
{
    private array $actor = ['isLoggedIn' => true, 'role' => 'admin', 'employee_id' => 7];
    private array $tables = ['files', 'tag', 'ai_event_detections'];
    private array $flash = [];

    protected function setUp(): void
    {
        parent::setUp();
        $session = $this->createMock(Session::class);
        $session->method('get')->willReturnCallback(fn ($key = null) => $key === null ? $this->actor : ($this->actor[$key] ?? null));
        $session->method('setFlashdata')->willReturnCallback(function ($key, $value = null) { $this->flash[$key] = $value; });
        Services::injectMock('session', $session);
        Services::injectMock('audit', $this->createMock(AuditService::class));
        $db = db_connect();
        $this->assertSame('SQLite3', $db->DBDriver);
        $this->assertSame(':memory:', $db->database);
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS ' . $db->prefixTable($table));
        $db->query('CREATE TABLE ' . $db->prefixTable('files') . ' (id INTEGER PRIMARY KEY, filename TEXT, filepath TEXT, filetype TEXT, tags TEXT, is_deleted INTEGER, classification TEXT, detected_objects TEXT, last_action TEXT, last_action_at TEXT)');
        $db->query('CREATE TABLE ' . $db->prefixTable('tag') . ' (tag_id INTEGER PRIMARY KEY, file_id INTEGER, tag TEXT, type TEXT, filepath TEXT)');
        $db->query('CREATE TABLE ' . $db->prefixTable('ai_event_detections') . ' (id INTEGER PRIMARY KEY, media_id INTEGER, event_name TEXT, needs_review INTEGER, reviewed_by INTEGER, reviewed_at TEXT, review_note TEXT)');
        foreach ([1, 2] as $id) {
            $db->table('files')->insert(['id' => $id, 'filename' => "photo-$id.jpg", 'filepath' => "photo-$id.jpg", 'filetype' => 'jpg', 'tags' => 'Campus, AI Review Needed', 'is_deleted' => 0]);
            $db->table('tag')->insert(['file_id' => $id, 'tag' => 'Campus', 'type' => 'image', 'filepath' => "photo-$id.jpg"]);
            $db->table('ai_event_detections')->insert(['id' => $id, 'media_id' => $id, 'needs_review' => 1]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) db_connect()->query('DROP TABLE IF EXISTS ' . db_connect()->prefixTable($table));
        parent::tearDown();
    }

    private function review(array $tags, bool $single = false): void
    {
        $request = new IncomingRequest(config('App'), new URI('http://example.com/'), null, new UserAgent());
        $request->setGlobal('post', ['file_ids' => [1, 2], 'event_name' => '', 'review_note' => 'Verified images', 'review_tags' => $tags]);
        $controller = new WorkspaceController();
        $controller->initController($request, new Response(config('App')), service('logger'));
        $single ? $controller->reviewEvent(1) : $controller->reviewEvents();
    }

    public function testBatchAddsTagsOnlyToTheirImageAndSynchronizesSearchTags(): void
    {
        $this->review([1 => 'Founder, founder, Campus, Group   photo', 2 => 'Library']);
        $this->assertArrayHasKey('success', $this->flash);
        $db = db_connect();
        $first = $db->table('files')->where('id', 1)->get()->getRowArray();
        $second = $db->table('files')->where('id', 2)->get()->getRowArray();
        $this->assertSame('Campus, founder, Group photo', $first['tags']);
        $this->assertSame('Campus, Library', $second['tags']);
        foreach ([$first, $second] as $file) {
            $stored = $db->table('tag')->where('file_id', $file['id'])->orderBy('tag_id')->get()->getResultArray();
            $this->assertSame(explode(', ', $file['tags']), array_column($stored, 'tag'));
            $this->assertSame('Unclassified university media', $file['classification']);
            $this->assertSame(0, (int) $db->table('ai_event_detections')->where('media_id', $file['id'])->get()->getRowArray()['needs_review']);
        }
    }

    public function testSingleReviewAlsoSavesTagsAndPreservesExistingTags(): void
    {
        $existing = implode(', ', array_map(static fn ($n) => 'Tag ' . $n, range(1, 31)));
        db_connect()->table('files')->where('id', 1)->update(['tags' => $existing]);
        $this->review([1 => 'Founder'], true);
        $this->assertArrayHasKey('success', $this->flash);
        $this->assertSame($existing . ', Founder', db_connect()->table('files')->where('id', 1)->get()->getRowArray()['tags']);
        $this->assertSame(32, db_connect()->table('tag')->where('file_id', 1)->countAllResults());
    }

    public function testInvalidTagsRejectWholeBatchBeforeAnyChanges(): void
    {
        foreach ([[1 => 'Founder', 2 => str_repeat('x', 51)], [1 => ['nested']], [99 => 'Founder'], [1 => implode(',', range(1, 26))]] as $tags) {
            $this->flash = [];
            $this->review($tags);
            $this->assertArrayHasKey('error', $this->flash);
            $this->assertSame(2, db_connect()->table('ai_event_detections')->where('needs_review', 1)->countAllResults());
            $this->assertSame(2, db_connect()->table('files')->where('tags', 'Campus, AI Review Needed')->countAllResults());
            $this->assertSame(2, db_connect()->table('tag')->countAllResults());
        }
    }

    public function testDepartmentCannotAddTagsThroughReview(): void
    {
        $this->actor['role'] = 'department';
        $this->review([1 => 'Founder']);
        $this->assertArrayHasKey('error', $this->flash);
        $this->assertSame(2, db_connect()->table('files')->where('tags', 'Campus, AI Review Needed')->countAllResults());
    }
}
