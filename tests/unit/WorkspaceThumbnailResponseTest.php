<?php

use App\Controllers\Web\WorkspaceController;
use App\Services\StorageService;
use App\Services\ThumbnailService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

final class WorkspaceThumbnailResponseTest extends CIUnitTestCase
{
    private string $source;
    private array $actor = ['isLoggedIn' => true, 'role' => 'admin'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = tempnam(sys_get_temp_dir(), 'greyhound-preview-');
        $image = imagecreatetruecolor(600, 300);
        imagejpeg($image, $this->source);
        imagedestroy($image);
        $session = $this->createMock(Session::class);
        $session->method('get')->willReturnCallback(fn ($key = null) => $key === null ? $this->actor : ($this->actor[$key] ?? null));
        Services::injectMock('session', $session);
        $storage = $this->createMock(StorageService::class);
        $storage->method('resolveDatabaseFile')->willReturn($this->source);
        Services::injectMock('storage', $storage);
        $db = db_connect();
        $this->assertSame('SQLite3', $db->DBDriver);
        $this->assertSame(':memory:', $db->database);
        $table = $db->prefixTable('files');
        $db->query('DROP TABLE IF EXISTS ' . $table);
        $db->query('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, filepath TEXT, is_deleted INTEGER)');
        $db->table('files')->insertBatch([
            ['id' => 1, 'filepath' => 'photo.jpg', 'is_deleted' => 0],
            ['id' => 2, 'filepath' => 'deleted.jpg', 'is_deleted' => 1],
        ]);
        $assignments = $db->prefixTable('media_assignments');
        $db->query('DROP TABLE IF EXISTS ' . $assignments);
        $db->query('CREATE TABLE ' . $assignments . ' (media_id INTEGER, department TEXT, status TEXT)');
    }

    protected function tearDown(): void
    {
        $isVideo = str_starts_with((string) (new finfo(FILEINFO_MIME_TYPE))->file($this->source), 'video/');
        $cached = $isVideo ? (new \App\Services\VideoPreviewService())->thumbnailForFile($this->source) : (new ThumbnailService())->forFile($this->source);
        unlink($cached);
        if (is_file($cached . '.lock')) unlink($cached . '.lock');
        unlink($this->source);
        Services::resetSingle('storage');
        Services::resetSingle('session');
        parent::tearDown();
    }

    private function controller(bool $thumbnail = true, string $etag = ''): WorkspaceController
    {
        $request = new IncomingRequest(config('App'), new URI('http://example.com/'), null, new UserAgent());
        $request->setGlobal('get', $thumbnail ? ['thumbnail' => '1'] : []);
        if ($etag !== '') $request->setHeader('If-None-Match', $etag);
        $controller = new WorkspaceController();
        $controller->initController($request, new Response(config('App')), service('logger'));
        return $controller;
    }

    public function testAuthorizedThumbnailUsesPrivateValidationAndReturns304OnRepeat(): void
    {
        $response = $this->controller()->preview(1);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('image/jpeg', $response->getHeaderLine('Content-Type'));
        $this->assertSame('private, no-cache, must-revalidate', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('Cookie', $response->getHeaderLine('Vary'));
        $size = getimagesizefromstring($response->getBody());
        $this->assertSame([400, 200], [$size[0], $size[1]]);
        $cached = $this->controller(true, $response->getHeaderLine('ETag'))->preview(1);
        $this->assertSame(304, $cached->getStatusCode());
        $this->assertSame('', $cached->getBody());
    }

    public function testPreviewRequestsReleaseSessionBeforeAccessingStorage(): void
    {
        foreach (['preview'=>1, 'videoPreview'=>1, 'trashPreview'=>2] as $method => $id) {
            $closed = false;
            $session = $this->createMock(Session::class);
            $session->method('get')->willReturnCallback(fn ($key = null) => $key === null ? $this->actor : ($this->actor[$key] ?? null));
            $session->expects($this->atLeastOnce())->method('close')->willReturnCallback(static function () use (&$closed) { $closed = true; });
            Services::injectMock('session', $session);
            $storage = $this->createMock(StorageService::class);
            $storage->expects($this->once())->method('resolveDatabaseFile')->willReturnCallback(function () use (&$closed) {
                $this->assertTrue($closed, 'Slow storage must never hold the session lock.');
                return $this->source;
            });
            Services::injectMock('storage', $storage);
            $response = $this->controller(false)->$method($id);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('image/jpeg', $response->getHeaderLine('Content-Type'));
        }
    }

    public function testUnauthorizedVideoPreviewNeverReadsStorage(): void
    {
        $this->actor['role'] = 'guest';
        $storage = $this->createMock(StorageService::class);
        $storage->expects($this->never())->method('resolveDatabaseFile');
        Services::injectMock('storage', $storage);
        $this->assertSame(403, $this->controller(false)->videoPreview(1)->getStatusCode());
    }

    public function testVideoThumbnailReturnsCachedJpegWithoutChangingTheOriginal(): void
    {
        $ffmpeg = null;
        foreach (['/opt/homebrew/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg'] as $binary) {
            if (is_executable($binary)) { $ffmpeg = $binary; break; }
        }
        if ($ffmpeg === null) $this->markTestSkipped('FFmpeg is required for video thumbnail tests.');
        $process = proc_open([$ffmpeg, '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=64x48:r=10', '-t', '0.3', '-c:v', 'mpeg4', '-f', 'mp4', $this->source], [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);
        $hash = hash_file('sha256', $this->source);
        $response = $this->controller()->preview(1);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', getimagesizefromstring($response->getBody())['mime']);
        $this->assertSame($hash, hash_file('sha256', $this->source));
        $trash = $this->controller()->trashPreview(2);
        $this->assertSame('image/jpeg', getimagesizefromstring($trash->getBody())['mime']);
        $this->assertSame('private, no-cache, must-revalidate', $response->getHeaderLine('Cache-Control'));
        $etag = $response->getHeaderLine('ETag');
        $this->assertSame(304, $this->controller(true, $etag)->preview(1)->getStatusCode());
        $this->actor = ['isLoggedIn' => true, 'role' => 'guest'];
        $this->assertSame(403, $this->controller(true, $etag)->preview(1)->getStatusCode());
    }

    public function testTrashImageThumbnailRevalidatesPermissionAndDoesNotChangeOriginal(): void
    {
        $hash = hash_file('sha256', $this->source);
        foreach (['jpeg', 'png'] as $format) {
            $image = imagecreatetruecolor(600, 300);
            $format === 'png' ? imagepng($image, $this->source) : imagejpeg($image, $this->source);
            imagedestroy($image);
            clearstatcache(true, $this->source);
            $hash = hash_file('sha256', $this->source);
            $response = $this->controller()->trashPreview(2);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('image/jpeg', getimagesizefromstring($response->getBody())['mime']);
            $this->assertSame($hash, hash_file('sha256', $this->source));
            $this->assertSame(304, $this->controller(true, $response->getHeaderLine('ETag'))->trashPreview(2)->getStatusCode());
        }
        $this->assertSame(404, $this->controller()->trashPreview(1)->getStatusCode());
        $this->assertSame(404, $this->controller()->trashPreview(999)->getStatusCode());
        foreach (['department', 'writer', 'guest'] as $role) {
            $this->actor['role'] = $role;
            $this->assertSame(403, $this->controller()->trashPreview(2)->getStatusCode());
            $this->assertSame(403, $this->controller()->serverTrashPreview(str_repeat('a', 32))->getStatusCode());
        }
        $this->actor = ['isLoggedIn'=>false];
        $this->assertSame(302, $this->controller()->serverTrashPreview(str_repeat('a', 32))->getStatusCode());
    }

    public function testServerTrashThumbnailUsesConfinedResolverAndReturnsUnavailableSafely(): void
    {
        $editor = $this->createMock(\App\Services\ServerLibraryEditor::class);
        $editor->expects($this->exactly(2))->method('previewPath')->with(str_repeat('a', 32), '')
            ->willReturnCallback(function () {
                static $calls = 0;
                if (++$calls === 2) throw new RuntimeException('Missing file');
                return $this->source;
            });
        $make = function () use ($editor) {
            $controller = new class($editor) extends WorkspaceController {
                public function __construct(private $editor) {}
                protected function serverTrashEditor(): \App\Services\ServerLibraryEditor { return $this->editor; }
            };
            $request = new IncomingRequest(config('App'), new URI('http://example.com/'), null, new UserAgent());
            $request->setGlobal('get', ['thumbnail'=>'1']);
            $controller->initController($request, new Response(config('App')), service('logger'));
            return $controller;
        };
        $response = $make()->serverTrashPreview(str_repeat('a', 32));
        $this->assertSame('image/jpeg', getimagesizefromstring($response->getBody())['mime']);
        $this->assertSame('private, no-cache, must-revalidate', $response->getHeaderLine('Cache-Control'));
        $this->assertSame(404, $make()->serverTrashPreview(str_repeat('a', 32))->getStatusCode());
    }

    public function testCachedThumbnailStillRequiresPermission(): void
    {
        $etag = $this->controller()->preview(1)->getHeaderLine('ETag');
        $this->actor['role'] = 'guest';
        $response = $this->controller(true, $etag)->preview(1);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('', $response->getHeaderLine('ETag'));
    }

    public function testDeletedAndMissingImagesCannotBeThumbnailed(): void
    {
        $this->assertSame(404, $this->controller()->preview(2)->getStatusCode());
        $this->assertSame(404, $this->controller()->preview(999)->getStatusCode());
    }

    public function testOpeningFullPreviewStillReturnsOriginalBytes(): void
    {
        $response = $this->controller(false)->preview(1);
        ob_start();
        try {
            $response->sendBody();
            $this->assertSame(file_get_contents($this->source), ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    public function testDepartmentPreviewSurvivesOriginalDeletionAndEnforcesOwnership(): void
    {
        $db = db_connect();
        $table = $db->prefixTable('department_files');
        $db->query('DROP TABLE IF EXISTS ' . $table);
        $db->query('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, department TEXT, filepath TEXT, is_deleted INTEGER)');
        $db->table('department_files')->insertBatch([
            ['id' => 1, 'department' => 'CCS', 'filepath' => 'department-copy.jpg', 'is_deleted' => 0],
            ['id' => 2, 'department' => 'CBA', 'filepath' => 'other-copy.jpg', 'is_deleted' => 0],
            ['id' => 3, 'department' => 'CCS', 'filepath' => 'deleted-copy.jpg', 'is_deleted' => 1],
        ]);
        $db->table('files')->where('id', 1)->delete();
        $this->actor = ['isLoggedIn' => true, 'role' => 'department', 'department' => 'CCS'];
        try {
            $this->assertSame(200, $this->controller()->preview(1)->getStatusCode());
            $this->assertSame(404, $this->controller()->preview(2)->getStatusCode());
            $this->assertSame(404, $this->controller()->preview(3)->getStatusCode());
            $this->actor['role'] = 'admin';
            $this->assertSame(404, $this->controller()->preview(1)->getStatusCode());
        } finally {
            $db->query('DROP TABLE ' . $table);
        }
    }


}
