<?php

use App\Controllers\Web\AddonsController;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

final class WatermarkPaginationTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $this->assertSame('SQLite3', $db->DBDriver);
        $this->assertSame(':memory:', $db->database);
        foreach (['files', 'folder'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $db->prefixTable($table));
        }
        $db->query('CREATE TABLE ' . $db->prefixTable('folder') . ' (id INTEGER PRIMARY KEY, name TEXT, parent_id INTEGER, is_deleted INTEGER DEFAULT 0)');
        $db->query('CREATE TABLE ' . $db->prefixTable('files') . ' (id INTEGER PRIMARY KEY, display_title TEXT, filename TEXT, filetype TEXT, size INTEGER, dateupload TEXT, folder_id INTEGER, tags TEXT, is_deleted INTEGER DEFAULT 0)');
        $db->table('folder')->insertBatch([
            ['id' => 1, 'name' => 'Events', 'parent_id' => null],
            ['id' => 2, 'name' => 'Other folder', 'parent_id' => null],
        ]);
        for ($id = 1; $id <= 48; $id++) {
            $db->table('files')->insert([
                'id' => $id, 'display_title' => $id <= 45 ? 'Campus event' : 'Other image',
                'filename' => "photo-{$id}." . ($id <= 45 ? 'jpg' : 'png'),
                'filetype' => $id <= 45 ? 'jpg' : 'png', 'size' => 1000,
                'dateupload' => '2026-09-18 10:00:00', 'folder_id' => $id <= 45 ? 1 : 2,
                'tags' => '', 'is_deleted' => $id === 48 ? 1 : 0,
            ]);
        }
        $actor = ['isLoggedIn' => true, 'employee_id' => 0, 'role' => 'admin', 'name' => 'Admin'];
        $session = $this->createMock(Session::class);
        $session->method('get')->willReturnCallback(static fn ($key = null) => $key === null ? $actor : ($actor[$key] ?? null));
        Services::injectMock('session', $session);
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['files', 'folder'] as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $db->prefixTable($table));
        }
        parent::tearDown();
    }

    private function page(array $query): string
    {
        $request = new IncomingRequest(config('App'), new URI('http://example.com/workspace/watermarking'), null, new UserAgent());
        $request->setGlobal('get', $query);
        $controller = new AddonsController();
        $controller->initController($request, new Response(config('App')), service('logger'));
        return $controller->watermarking();
    }

    private function imageIds(string $html): array
    {
        preg_match_all('/data-image-id="(\d+)"/', $html, $matches);
        // The same page is rendered in both grid and list views.
        return array_values(array_unique(array_map('intval', $matches[1])));
    }

    public function testPagesContainAtMostTwentyImagesWithoutDuplicatesOrGaps(): void
    {
        $ids = [];
        foreach ([1 => 20, 2 => 20, 3 => 5] as $page => $count) {
            $html = $this->page(['folder' => 1, 'page' => $page, 'type' => 'jpg']);
            $pageIds = $this->imageIds($html);
            $this->assertCount($count, $pageIds);
            $ids = array_merge($ids, $pageIds);
            $this->assertStringContainsString('aria-label="Watermark image pages"', $html);
            $this->assertStringContainsString('folder=1&amp;type=jpg', $html);
        }
        $this->assertSame(range(45, 1), $ids);
    }

    public function testSearchPaginationRetainsQueryAndType(): void
    {
        $html = $this->page(['q' => 'Campus event', 'type' => 'jpg', 'page' => 2]);
        $this->assertSame(range(25, 6), $this->imageIds($html));
        $this->assertStringContainsString('Showing 21–40 of 45 images', $html);
        $this->assertStringContainsString('page=3&amp;q=Campus+event&amp;type=jpg', $html);
    }

    public function testOutOfRangePagesClampToAvailableResults(): void
    {
        $this->assertSame(range(45, 26), $this->imageIds($this->page(['folder' => 1, 'page' => -2])));
        $html = $this->page(['folder' => 1, 'page' => 999]);
        $this->assertSame(range(5, 1), $this->imageIds($html));
        $this->assertStringContainsString('Showing 41–45 of 45 images', $html);
        $this->assertStringContainsString('aria-disabled="true">Next', $html);
    }

    public function testEmptyAndSinglePageResultsHaveNoPageLinks(): void
    {
        $empty = $this->page(['folder' => 1, 'type' => 'png']);
        $this->assertSame([], $this->imageIds($empty));
        $this->assertStringContainsString('Showing 0–0 of 0 images', $empty);
        $this->assertStringNotContainsString('aria-label="Watermark image pages"', $empty);
        $single = $this->page(['folder' => 2, 'type' => 'png']);
        $this->assertSame([47, 46], $this->imageIds($single));
        $this->assertStringContainsString('Showing 1–2 of 2 images', $single);
        $this->assertStringNotContainsString('aria-label="Watermark image pages"', $single);
    }
}
