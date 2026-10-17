<?php

use App\Controllers\Web\DistributionController;
use App\Services\StorageService;
use App\Services\ThumbnailService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

final class DistributionThumbnailTest extends CIUnitTestCase
{
    private string $source;
    private array $actor = ['isLoggedIn'=>true, 'role'=>'department', 'department'=>'CCS'];
    private array $tables = ['media_requests','media_assignments','department_files'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = tempnam(sys_get_temp_dir(), 'distribution-photo-');
        $image = imagecreatetruecolor(800, 400);
        imagejpeg($image, $this->source);
        imagedestroy($image);
        $storage = $this->createMock(StorageService::class);
        $storage->method('resolveDatabaseFile')->willReturn($this->source);
        Services::injectMock('storage', $storage);
        $session = $this->createMock(Session::class);
        $session->method('get')->willReturnCallback(fn($key=null)=>$key===null?$this->actor:($this->actor[$key]??null));
        Services::injectMock('session', $session);
        $db = db_connect();
        $this->assertSame('SQLite3', $db->DBDriver);
        $this->assertSame(':memory:', $db->database);
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS '.$db->prefixTable($table));
        $db->query('CREATE TABLE '.$db->prefixTable('media_requests').' (id INTEGER PRIMARY KEY, department TEXT)');
        $db->query('CREATE TABLE '.$db->prefixTable('media_assignments').' (id INTEGER PRIMARY KEY, request_id INTEGER, media_id INTEGER, status TEXT)');
        $db->query('CREATE TABLE '.$db->prefixTable('department_files').' (id INTEGER PRIMARY KEY, source_assignment_id INTEGER, department TEXT, filepath TEXT, is_deleted INTEGER)');
        $db->table('media_requests')->insert(['id'=>1,'department'=>'CCS']);
        $db->table('media_assignments')->insert(['id'=>9,'request_id'=>1,'media_id'=>42,'status'=>'completed']);
        $db->table('department_files')->insert(['id'=>3,'source_assignment_id'=>9,'department'=>'CCS','filepath'=>'copy.jpg','is_deleted'=>0]);
    }

    protected function tearDown(): void
    {
        $isVideo = str_starts_with((new finfo(FILEINFO_MIME_TYPE))->file($this->source), 'video/');
        $cached = $isVideo ? (new \App\Services\VideoPreviewService())->thumbnailForFile($this->source) : (new ThumbnailService())->forFile($this->source);
        unlink($cached);
        if (is_file($cached . '.lock')) unlink($cached . '.lock');
        unlink($this->source);
        foreach ($this->tables as $table) db_connect()->query('DROP TABLE IF EXISTS '.db_connect()->prefixTable($table));
        parent::tearDown();
    }

    private function response(int $fileId=42, string $etag='')
    {
        $request = new IncomingRequest(config('App'), new URI('http://example.com/'), null, new UserAgent());
        $request->setGlobal('get', ['thumbnail'=>'1']);
        if ($etag !== '') $request->setHeader('If-None-Match', $etag);
        $controller = new DistributionController();
        $controller->initController($request, new Response(config('App')), service('logger'));
        return $controller->reviewPreview(1, $fileId);
    }

    public function testCompletedCopyProducesSmallPrivateThumbnailWithoutOriginal(): void
    {
        $response = $this->response();
        $this->assertSame(200, $response->getStatusCode());
        $size = getimagesizefromstring($response->getBody());
        $this->assertSame([400,200], [$size[0],$size[1]]);
        $this->assertSame('image/jpeg', $size['mime']);
        $this->assertSame('private, no-cache, must-revalidate', $response->getHeaderLine('Cache-Control'));
        $this->assertSame(304, $this->response(42, $response->getHeaderLine('ETag'))->getStatusCode());
    }

    public function testCacheCannotBypassDepartmentAssignmentOrDeletionChecks(): void
    {
        $etag = $this->response()->getHeaderLine('ETag');
        $this->assertSame(403, $this->response(999, $etag)->getStatusCode());
        $this->actor['department'] = 'CBA';
        $this->assertSame(403, $this->response(42, $etag)->getStatusCode());
        $this->actor['department'] = 'CCS';
        db_connect()->table('department_files')->where('id',3)->update(['is_deleted'=>1]);
        $this->assertSame(404, $this->response(42, $etag)->getStatusCode());
    }

    public function testVideoTransactionReturnsPosterImageInsteadOfVideoBytes(): void
    {
        $ffmpeg = null;
        foreach (['/opt/homebrew/bin/ffmpeg','/usr/local/bin/ffmpeg','/usr/bin/ffmpeg'] as $binary) {
            if (is_executable($binary)) { $ffmpeg = $binary; break; }
        }
        if ($ffmpeg === null) $this->markTestSkipped('FFmpeg is required for video poster tests.');
        $process = proc_open([$ffmpeg, '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=64x48:r=10', '-t', '0.3', '-c:v', 'mpeg4', '-f', 'mp4', $this->source], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);
        $hash = hash_file('sha256', $this->source);
        $response = $this->response();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', getimagesizefromstring($response->getBody())['mime']);
        $this->assertSame($hash, hash_file('sha256', $this->source));
    }
}
