<?php

use App\Controllers\Api\MobileFeedController;
use App\Services\StorageService;
use App\Services\ThumbnailService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

final class MobileThumbnailResponseTest extends CIUnitTestCase
{
    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = tempnam(sys_get_temp_dir(), 'greyhound-mobile-thumb-');
        $image = imagecreatetruecolor(600, 300);
        imagejpeg($image, $this->source);
        imagedestroy($image);
        $storage = $this->createMock(StorageService::class);
        $storage->method('resolveDatabaseFile')->willReturn($this->source);
        Services::injectMock('storage', $storage);
        Services::injectMock('session', $this->createMock(Session::class));
        $db = db_connect();
        $db->query('DROP TABLE IF EXISTS ' . $db->prefixTable('files'));
        $db->query('CREATE TABLE ' . $db->prefixTable('files') . ' (id INTEGER PRIMARY KEY, filepath TEXT, media_kind TEXT, release_status TEXT, is_deleted INTEGER, app_published_at TEXT DEFAULT \'2026-09-01 00:00:00\')');
        $db->table('files')->insertBatch([
            ['id' => 1, 'filepath' => 'photo.jpg', 'media_kind' => 'image', 'release_status' => 'published', 'is_deleted' => 0],
            ['id' => 2, 'filepath' => 'draft.jpg', 'media_kind' => 'image', 'release_status' => 'draft', 'is_deleted' => 0],
            ['id' => 3, 'filepath' => 'deleted.jpg', 'media_kind' => 'image', 'release_status' => 'published', 'is_deleted' => 1],
            ['id' => 4, 'filepath' => 'document.pdf', 'media_kind' => 'document', 'release_status' => 'published', 'is_deleted' => 0],
        ]);
    }

    protected function tearDown(): void
    {
        unlink((new ThumbnailService())->forFile($this->source));
        unlink($this->source);
        Services::resetSingle('storage'); Services::resetSingle('session');
        parent::tearDown();
    }

    private function controller(string $etag = '', string $range = ''): MobileFeedController
    {
        $request = new IncomingRequest(config('App'), new URI('http://example.com/'), null, new UserAgent());
        $request->setHeader('If-None-Match', $etag);
        $request->setHeader('Range', $range);
        $controller = new MobileFeedController();
        $controller->initController($request, new Response(config('App')), service('logger'));
        return $controller;
    }

    public function testPublishedThumbnailReturnsJpegAndRevalidatesCache(): void
    {
        $response = $this->controller()->thumbnail(1);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', $response->getHeaderLine('Content-Type'));
        $this->assertSame('private, no-cache', $response->getHeaderLine('Cache-Control'));
        ob_start();
        try {
            $response->sendBody();
            $size = getimagesizefromstring(ob_get_contents());
            $this->assertSame([400, 200], [$size[0], $size[1]]);
        } finally { ob_end_clean(); }
        $this->assertSame(304, $this->controller($response->getHeaderLine('ETag'))->thumbnail(1)->getStatusCode());
        db_connect()->table('files')->where('id', 1)->update(['release_status' => 'draft']);
        $this->assertSame(404, $this->controller($response->getHeaderLine('ETag'))->thumbnail(1)->getStatusCode());
    }

    public function testThumbnailRejectsDraftDeletedMissingAndUnsupportedMedia(): void
    {
        foreach ([2, 3, 4, 999] as $id) $this->assertSame(404, $this->controller()->thumbnail($id)->getStatusCode());
    }

    public function testLargeMobileStreamStaysWithin128MbAndHonorsRanges(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'greyhound-mobile-video-');
        $size = 198792088;
        $handle = fopen($path, 'w+b');
        fwrite($handle, 'VIDEO'); ftruncate($handle, $size); fclose($handle);
        $method = new ReflectionMethod(MobileFeedController::class, 'streamVideoResponse');
        $previousLimit = ini_get('memory_limit');
        ini_set('memory_limit', '128M');
        try {
            $expected = hash_file('sha256', $path);
            $response = $method->invoke($this->controller('', 'bytes=0-'), $path, 'video/mp4');
            $this->assertSame(206, $response->getStatusCode());
            $this->assertSame('bytes 0-' . ($size - 1) . '/' . $size, $response->getHeaderLine('Content-Range'));
            $this->assertEmpty($response->getBody());
            $received = 0; $hash = hash_init('sha256');
            ob_start(static function ($chunk) use (&$received, $hash) {
                $received += strlen($chunk); hash_update($hash, $chunk); return '';
            }, 1);
            try { $response->sendBody(); } finally { ob_end_flush(); }
            $this->assertSame($size, $received);
            $this->assertSame($expected, hash_final($hash));
            $range = $method->invoke($this->controller('', 'bytes=0-4'), $path, 'video/mp4');
            ob_start();
            try { $range->sendBody(); $this->assertSame('VIDEO', ob_get_contents()); } finally { ob_end_clean(); }
        } finally {
            ini_set('memory_limit', $previousLimit);
            unlink($path);
        }
    }
}
