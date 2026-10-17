<?php

use App\Controllers\Web\ServerLibraryController;
use App\Services\ServerLibraryService;
use App\Services\ThumbnailService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Config\Storage;

final class ServerLibraryControllerTest extends CIUnitTestCase
{
    private string $root;
    private ServerLibraryService $library;
    private array $actor = ['isLoggedIn'=>true,'role'=>'admin'];
    private ?string $cache = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/server-controller-'.bin2hex(random_bytes(6));
        mkdir($this->root);
        $image = imagecreatetruecolor(800,400);
        imagejpeg($image,$this->root.'/photo.jpg'); imagedestroy($image);
        $config = new class extends Storage {
            public string $source;
            public function scanSourceRoot(): string { return $this->source; }
        };
        $config->source = $this->root;
        $cache = $this->createMock(\CodeIgniter\Cache\CacheInterface::class);
        $cache->method('get')->willReturn(null);
        $cache->method('save')->willReturn(true);
        $this->library = new ServerLibraryService($config,$cache);
        $session = $this->createMock(Session::class);
        $session->method('get')->willReturnCallback(fn($key=null)=>$key===null?$this->actor:($this->actor[$key]??null));
        Services::injectMock('session',$session);
    }

    protected function tearDown(): void
    {
        if ($this->cache !== null) {
            if (is_file($this->cache)) unlink($this->cache);
            if (is_file($this->cache.'.lock')) unlink($this->cache.'.lock');
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->root);
        parent::tearDown();
    }

    private function call(string $method, array $query = [], string $range = '', array $post = [], array $arguments = [], bool $ajax = false)
    {
        $controller = new class($this->library) extends \App\Controllers\Web\ServerLibraryActionsController {
            public function __construct(private ServerLibraryService $testLibrary) {}
            protected function library(): ServerLibraryService { return $this->testLibrary; }
            protected function catalog(): ServerLibraryService { return $this->testLibrary; }
            protected function recordEdit(string $action, array $paths): void {}
        };
        $request = new IncomingRequest(config('App'),new URI('http://example.com/'),null,new UserAgent());
        $request->setGlobal('get',$query);
        $request->setGlobal('post',$post);
        if ($ajax) { $request->setHeader('X-Requested-With', 'XMLHttpRequest'); $request->setHeader('Accept', 'application/json'); }
        if ($range !== '') $request->setHeader('Range',$range);
        $controller->initController($request,new Response(config('App')),service('logger'));
        return $controller->$method(...$arguments);
    }

    public function testServerLibraryUploadRecordsSavedPath(): void
    {
        $this->actor += ['employee_id'=>7, 'name'=>'Uploader', 'department'=>'CCS'];
        $file = new \App\Libraries\AssembledUpload($this->root.'/photo.jpg', 'photo.jpg');
        $uploads = $this->createMock(\App\Services\ServerLibraryUploadService::class);
        $uploads->expects($this->once())->method('receive')->willReturn([
            'ok'=>true, 'complete'=>true, 'uploaded'=>1, 'path'=>'Events/photo_1.jpg',
        ]);
        $controller = new class($uploads) extends ServerLibraryController {
            public array $recorded = [];
            public function __construct(private \App\Services\ServerLibraryUploadService $testUploads) {}
            protected function access() { return null; }
            protected function uploads(): \App\Services\ServerLibraryUploadService { return $this->testUploads; }
            protected function recordUploadBatch(array $actor, array $metadata, string $path, bool $success): void { $this->recorded[] = [$actor, $path, $success]; }
        };
        $request = $this->getMockBuilder(IncomingRequest::class)
            ->setConstructorArgs([config('App'), new URI('http://example.com/'), null, new UserAgent()])
            ->onlyMethods(['getFile'])->getMock();
        $request->method('getFile')->willReturn($file);
        $request->setGlobal('post', []);
        $controller->initController($request, new Response(config('App')), service('logger'));
        $response = $controller->upload();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue(json_decode($response->getBody(), true)['ok']);
        $this->assertSame([[$this->actor, 'Events/photo_1.jpg', true]], $controller->recorded);
    }

    public function testFolderMoveReturnsJsonCompletionAndValidationErrors(): void
    {
        mkdir($this->root.'/Source'); mkdir($this->root.'/Archive');
        file_put_contents($this->root.'/Source/keep.txt','contents');
        $response=$this->call('folderSelection', [], '', ['action'=>'move','paths'=>['Source'],'destination'=>'Archive'], [], true);
        $this->assertSame(200,$response->getStatusCode());
        $this->assertTrue(json_decode($response->getBody(),true)['ok']);
        $this->assertSame('contents',file_get_contents($this->root.'/Archive/Source/keep.txt'));
        $this->assertDirectoryDoesNotExist($this->root.'/Source');
        $response=$this->call('folderSelection', [], '', ['action'=>'move','paths'=>['Archive'],'destination'=>'Archive/Source'], [], true);
        $this->assertSame(422,$response->getStatusCode());
        $payload=json_decode($response->getBody(),true);
        $this->assertFalse($payload['ok']);
        $this->assertNotEmpty($payload['message']);
        $this->assertFileExists($this->root.'/Archive/Source/keep.txt');
    }

    public function testUploadPickerCreatesSubfolderAndReturnsDestinationWithoutRedirect(): void
    {
        mkdir($this->root.'/Events');
        $response = $this->call('action', [], '', ['parent_id'=>'Events', 'name'=>'Photos'], ['create-folder'], true);
        $payload = json_decode($response->getBody(), true);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($payload['ok']);
        $this->assertSame('Events/Photos', $payload['folder']['id']);
        $this->assertDirectoryExists($this->root.'/Events/Photos');
        $this->assertArrayHasKey('csrfToken', $payload);
        $duplicate = $this->call('action', [], '', ['parent_id'=>'Events', 'name'=>'Photos'], ['create-folder'], true);
        $this->assertSame(422, $duplicate->getStatusCode());
        $this->assertFalse(json_decode($duplicate->getBody(), true)['ok']);
        $invalid = $this->call('action', [], '', ['parent_id'=>'Events', 'name'=>'../Outside'], ['create-folder'], true);
        $this->assertSame(422, $invalid->getStatusCode());
        $this->assertDirectoryDoesNotExist($this->root.'/Outside');
    }

    public function testUnchangedCatalogPollSkipsRenderingAndChangesStillRender(): void
    {
        $html = $this->call('index');
        preg_match('/data-catalog-version="([a-f0-9]{64})"/', $html, $match);
        $this->assertNotEmpty($match[1]);
        $query = ['_catalog_poll'=>'1', '_catalog_version'=>$match[1]];
        $response = $this->call('index', $query);
        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame('', $response->getBody());
        file_put_contents($this->root . '/new.txt', 'new entry');
        $this->assertStringContainsString('new.txt', $this->call('index', $query));
    }

    public function testItemReleasesSessionBeforeReadingTheNas(): void
    {
        $closed = false;
        $session = $this->createMock(Session::class);
        $session->method('get')->willReturnCallback(fn ($key = null) => $key === null ? $this->actor : ($this->actor[$key] ?? null));
        $session->expects($this->once())->method('close')->willReturnCallback(function () use (&$closed) { $closed = true; });
        Services::injectMock('session', $session);
        $library = $this->createMock(ServerLibraryService::class);
        $library->method('relative')->willReturnArgument(0);
        $library->expects($this->once())->method('resolve')->willReturnCallback(function () use (&$closed) {
            $this->assertTrue($closed, 'NAS reads must never block other requests behind the session lock.');
            return $this->root . '/photo.jpg';
        });
        $this->library = $library;
        $this->assertInstanceOf(\App\Libraries\MediaStreamResponse::class, $this->call('item', [], '', [], [bin2hex('photo.jpg'), 'preview']));
    }

    public function testAccessIsRequiredForEveryEndpoint(): void
    {
        foreach (['index','status','media','send','counts','files','edit','trash','post','downloadSelected','upload','uploadToken','suggestions','folders','passport'] as $method) {
            $this->actor = ['isLoggedIn'=>false];
            $this->assertSame(302,$this->call($method)->getStatusCode());
            $this->actor = ['isLoggedIn'=>true,'role'=>'department','department'=>'CCS'];
            $this->assertSame(403,$this->call($method)->getStatusCode());
        }
    }

    public function testServerUploadControlsUseSharedQueueAndPreserveFolderPaths(): void
    {
        helper('dashboard');
        mkdir($this->root.'/Events');
        $html = view('workspace/server_library', array_merge($this->library->browse(), [
            'user'=>['isLoggedIn'=>true,'role'=>'admin','employee_id'=>0], 'activePage'=>'file-management','title'=>'Server Library',
            'folder'=>'','query'=>'','type'=>'','connected'=>true,'writable'=>true,'error'=>null,'success'=>null,
            'canManage'=>true,'canUpload'=>true,'canSend'=>false,'departments'=>[], 'duplicates'=>[],
        ]));
        foreach (['data-library="server"','id="uploadReviewList"','data-upload-picker="folder"','workspace/server-library/upload/token','value="Events"','id="mediaSearchSuggestions"','id="mediaTypeDropdown"'] as $expected) $this->assertStringContainsString($expected,$html);
        $this->assertStringNotContainsString('id="serverUploadModal"',$html);
        $this->assertStringNotContainsString('id="duplicatesModal"',$html);
        $this->assertStringNotContainsString('Duplicate scan mode',$html);
        $this->assertStringNotContainsString('Duplicates (',$html);
        $this->assertDoesNotMatchRegularExpression('/<input\b[^>]*name="duplicate_mode"/',$html);
        $this->assertStringContainsString('id="uploadBatchId"',$html);
        $localUpload = view('components/media_upload', ['uploadLibrary'=>'local','folderOptions'=>[],'folderId'=>null]);
        $this->assertStringNotContainsString('Duplicate scan mode',$localUpload);
        $this->assertStringNotContainsString('name="duplicate_mode"',$localUpload);
        $this->assertSame(1,substr_count($html,'id="batchUploadForm"'));
        $this->assertSame(1,substr_count($html,'id="uploadWorkspace"'));
        $this->actor = ['isLoggedIn'=>true,'role'=>'writer'];
        foreach (['upload','uploadToken'] as $method) $this->assertSame(403,$this->call($method)->getStatusCode());
        $this->assertSame(404,$this->call('media',['duplicate'=>str_repeat('a',32)])->getStatusCode());
    }

    public function testSuggestionsAndFileTypeFilterRespectServerFolder(): void
    {
        mkdir($this->root.'/Events');
        file_put_contents($this->root.'/Events/founder.jpg','fixture');
        (new \App\Services\ServerLibraryEditor($this->library))->addTags(['Events/founder.jpg'],['Founders Day']);
        $this->assertSame([['id'=>'Events','name'=>'Events']],json_decode($this->call('folders')->getBody(),true)['folders']);
        $response = $this->call('suggestions',['q'=>'found','folder'=>'Events','type'=>'jpg']);
        $this->assertSame(['Founders Day'],array_column(json_decode($response->getBody(),true)['suggestions'],'value'));
        $response = $this->call('suggestions',['q'=>'found','folder'=>'Events','type'=>'png']);
        $this->assertSame([],json_decode($response->getBody(),true)['suggestions']);
        $this->assertSame(0,json_decode($this->call('files',['folder'=>'Events','type'=>'png'])->getBody(),true)['file_total']);
        $this->assertSame(1,json_decode($this->call('files',['folder'=>'Events','type'=>'jpg'])->getBody(),true)['file_total']);
        $this->assertSame(0,json_decode($this->call('files',['folder'=>'Events','q'=>'founder.jpg'])->getBody(),true)['file_total']);
        $this->assertSame(1,json_decode($this->call('files',['folder'=>'Events','q'=>'Founders Day'])->getBody(),true)['file_total']);
        $html = $this->call('index', ['q'=>'Founders Day']);
        $this->assertStringContainsString('Matching Images', $html);
        $this->assertStringContainsString('founder.jpg', $html);
        $this->actor['role'] = 'writer';
        $response = $this->call('suggestions',['q'=>'found','folder'=>'Events','type'=>'jpg']);
        $this->assertSame(['founder.jpg','Founders Day'],array_column(json_decode($response->getBody(),true)['suggestions'],'value'));
    }

    public function testOnlyManagersCanEditAndTrashCanBeRestored(): void
    {
        $this->actor = ['isLoggedIn'=>true, 'role'=>'writer'];
        foreach (['create-folder','rename','move','tags','trash','restore','upload'] as $action) {
            $this->assertSame(403, $this->call('edit', [], '', ['action'=>$action])->getStatusCode());
        }
        $this->assertSame(403, $this->call('trash')->getStatusCode());
        $this->assertSame(403, $this->call('post')->getStatusCode());
        $this->actor = ['isLoggedIn'=>true, 'role'=>'admin', 'employee_id'=>7];
        $response = $this->call('edit', [], '', ['action'=>'create-folder', 'folder'=>'', 'name'=>'Events']);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertDirectoryExists($this->root . '/Events');
        $this->call('edit', [], '', ['action'=>'tags', 'paths'=>['photo.jpg'], 'tags'=>'Founder, founder']);
        $this->assertStringContainsString('founder', $this->call('files')->getBody());
        $this->call('edit', [], '', ['action'=>'trash', 'paths'=>['photo.jpg']]);
        $this->assertFileDoesNotExist($this->root . '/photo.jpg');
        $this->assertStringEndsWith('/workspace/trash', $this->call('trash')->getHeaderLine('Location'));
        $state = (new \App\Services\ServerLibraryEditor($this->library))->state();
        $this->call('edit', [], '', ['action'=>'restore', 'trash_id'=>array_key_first($state['trash'])]);
        $this->assertFileExists($this->root . '/photo.jpg');
        $this->assertSame([], (new \App\Services\ServerLibraryEditor($this->library))->state()['trash']);
    }

    public function testBulkDownloadUsesOnlyConfinedSelectedFiles(): void
    {
        $response = $this->call('downloadSelected', ['paths'=>['photo.jpg']]);
        $this->assertInstanceOf(\CodeIgniter\HTTP\DownloadResponse::class, $response);
        $response->buildHeaders();
        $this->assertStringContainsString('.zip', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame(302, $this->call('downloadSelected', ['paths'=>['../secret']])->getStatusCode());
        $this->assertSame(302, $this->call('downloadSelected', ['paths'=>[]])->getStatusCode());
    }

    public function testThumbnailCannotBeServedAfterDisconnectAndSourceIsUnchanged(): void
    {
        $hash = hash_file('sha256',$this->root.'/photo.jpg');
        $response = $this->call('media',['path'=>'photo.jpg','thumbnail'=>'1']);
        $this->cache = (new ThumbnailService())->forFile($this->root.'/photo.jpg');
        $this->assertSame(200,$response->getStatusCode());
        $size = getimagesizefromstring($response->getBody());
        $this->assertSame([400,200],[$size[0],$size[1]]);
        $this->assertStringContainsString('no-store',$response->getHeaderLine('Cache-Control'));
        rename($this->root,$this->root.'-offline');
        try {
            $this->assertFalse(json_decode($this->call('status')->getBody(),true)['connected']);
            $this->assertSame(404,$this->call('media',['path'=>'photo.jpg','thumbnail'=>'1'])->getStatusCode());
        } finally { rename($this->root.'-offline',$this->root); }
        $this->assertTrue(json_decode($this->call('status')->getBody(),true)['connected']);
        $this->assertSame($hash,hash_file('sha256',$this->root.'/photo.jpg'));
    }

    public function testRangePreviewAndInvalidPaths(): void
    {
        $response = $this->call('media',['path'=>'photo.jpg'],'bytes=0-9');
        $this->assertSame(206,$response->getStatusCode());
        $this->assertSame('10',$response->getHeaderLine('Content-Length'));
        ob_start(); $response->sendBody(); $bytes = ob_get_clean();
        $this->assertSame(file_get_contents($this->root.'/photo.jpg',false,null,0,10),$bytes);
        $this->assertSame(416,$this->call('media',['path'=>'photo.jpg'],'bytes=999999-')->getStatusCode());
        $this->assertSame(404,$this->call('media',['path'=>'../.env','download'=>'1'])->getStatusCode());
    }

    public function testDownloadReturnsTheOriginalFileAsAnAttachment(): void
    {
        $response = $this->call('media', ['path'=>'photo.jpg', 'download'=>'1']);
        $this->assertInstanceOf(\CodeIgniter\HTTP\DownloadResponse::class, $response);
        $response->buildHeaders();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('attachment;', $response->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('photo.jpg', $response->getHeaderLine('Content-Disposition'));
        ob_start();
        try {
            $response->sendBody();
            $this->assertSame(file_get_contents($this->root.'/photo.jpg'), ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    public function testLargeVideoHeaderSupportsRangesAndDoesNotTrustTheExtension(): void
    {
        $path = $this->root . '/large.mp4';
        $header = pack('N', 24) . 'ftypisom' . pack('N', 0) . 'isommp42';
        $file = fopen($path, 'wb');
        fwrite($file, $header);
        ftruncate($file, 512 * 1024 * 1024);
        fclose($file);
        $response = $this->call('media', ['path'=>'large.mp4'], 'bytes=0-23');
        $this->assertSame(206, $response->getStatusCode());
        $this->assertSame('video/mp4', $response->getHeaderLine('Content-Type'));
        $this->assertSame('bytes 0-23/536870912', $response->getHeaderLine('Content-Range'));
        ob_start();
        try {
            $response->sendBody();
            $this->assertSame($header, ob_get_contents());
        } finally { ob_end_clean(); }
        $first = $this->call('media', ['path'=>'large.mp4'], 'bytes=0-');
        $this->assertSame('4194304', $first->getHeaderLine('Content-Length'));
        $this->assertSame('bytes 0-4194303/536870912', $first->getHeaderLine('Content-Range'));
        $next = $this->call('media', ['path'=>'large.mp4'], 'bytes=4194304-');
        $this->assertSame('bytes 4194304-8388607/536870912', $next->getHeaderLine('Content-Range'));
        $last = $this->call('media', ['path'=>'large.mp4'], 'bytes=536870900-');
        $this->assertSame('12', $last->getHeaderLine('Content-Length'));
        $this->assertSame('bytes 536870900-536870911/536870912', $last->getHeaderLine('Content-Range'));
        file_put_contents($this->root . '/fake.mp4', '<html><body>Not a video</body></html>');
        $this->assertSame(415, $this->call('media', ['path'=>'fake.mp4'])->getStatusCode());
    }

    public function testFileBatchesAndDeferredCountsRespectSearchAndAccess(): void
    {
        $this->actor = ['isLoggedIn'=>true,'role'=>'writer'];
        for ($i=1;$i<=65;$i++) file_put_contents($this->root.'/batch'.$i.'.txt','fixture');
        try {
            $response = $this->call('files',['q'=>'batch','offset'=>30]);
            $data = json_decode($response->getBody(),true);
            $this->assertSame(65,$data['file_total']);
            $this->assertSame(60,$data['next_offset']);
            $this->assertSame(30,substr_count($data['html'],'data-server-file='));
            $this->assertStringNotContainsString('name="paths[]"',$data['html']);
            $this->assertStringContainsString('batch31.txt',$data['html']);
            $this->assertStringNotContainsString('photo.jpg',$data['html']);
            $this->assertStringContainsString('listing;dur=',$response->getHeaderLine('Server-Timing'));
            $last = json_decode($this->call('files',['q'=>'batch','offset'=>60])->getBody(),true);
            $this->assertNull($last['next_offset']);
            $this->assertSame(5,substr_count($last['html'],'data-server-file='));
            $this->assertSame(66,json_decode($this->call('counts')->getBody(),true)['counts']['files']);
            $this->assertSame(404,$this->call('counts',['folder'=>'../private'])->getStatusCode());
            $this->assertSame(404,$this->call('files',['folder'=>'../private'])->getStatusCode());
            rename($this->root,$this->root.'-offline');
            try {
                $this->assertSame(404,$this->call('counts')->getStatusCode());
                $this->assertSame(404,$this->call('files')->getStatusCode());
            } finally { rename($this->root.'-offline',$this->root); }
        } finally { for ($i=1;$i<=65;$i++) unlink($this->root.'/batch'.$i.'.txt'); }
    }

    public function testCameraImagesHavePreviewControlsAndOriginalDownloadLinks(): void
    {
        foreach (['CR2', 'NEF', 'TIFF', 'HEIC'] as $extension) file_put_contents($this->root . '/camera.' . $extension, 'fixture');
        $html = $this->call('index');
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        foreach (['CR2', 'NEF', 'TIFF', 'HEIC'] as $extension) {
            $token = bin2hex('camera.' . $extension);
            $buttons = $xpath->query('//button[@data-preview-kind="image" and contains(@data-preview-src, "' . $token . '/preview")]');
            $this->assertGreaterThan(0, $buttons->length, $extension);
            $this->assertStringEndsWith($token . '/download', $buttons->item(0)->getAttribute('data-download-url'));
            $this->assertSame('', $buttons->item(0)->getAttribute('data-logo-url'));
        }
    }

    public function testCatalogVideoCardsLoadOnlyQueuedPostersUntilPlaybackIsRequested(): void
    {
        file_put_contents($this->root . '/clip.mov', 'video fixture');
        $html = $this->call('index');
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $this->assertSame(0, $xpath->query('//video[@src] | //video/source[@src]')->length);
        $cards = $xpath->query('//*[@id="mediaGridView"]//button[@data-preview-kind="video" and contains(@class, "grid-thumbnail")]');
        $this->assertSame(1, $cards->length);
        $card = $cards->item(0);
        $this->assertStringEndsWith('/video-preview', $card->getAttribute('data-preview-src'));
        $posters = $xpath->query('.//img[@data-server-thumbnail]', $card);
        $this->assertSame(1, $posters->length);
        $this->assertFalse($posters->item(0)->hasAttribute('src'), 'The thumbnail queue controls when NAS requests start.');
        $this->assertStringEndsWith('/preview?thumbnail=1', $posters->item(0)->getAttribute('data-server-thumbnail'));
    }

    public function testCatalogNavigationKeepsUploadsAvailableWithoutConnectionRefresh(): void
    {
        mkdir($this->root . '/Events');
        foreach (['', 'Events', ''] as $folder) {
            $html = $this->call('index', ['folder'=>$folder]);
            $this->assertStringContainsString('id="serverStorageStatus" hidden', $html);
            $this->assertSame(1, preg_match('/<button[^>]*id="mediaNewButton"[^>]*>/', $html, $button));
            $this->assertStringNotContainsString('disabled', $button[0]);
            $this->assertStringContainsString('data-upload-picker="files"', $html);
        }
        $this->actor = ['isLoggedIn'=>true, 'role'=>'writer'];
        $this->assertStringNotContainsString('id="mediaNewButton"', $this->call('index', ['folder'=>'Events']));
    }

    public function testPageRendersAndWriterCannotSend(): void
    {
        $this->actor = ['isLoggedIn'=>true,'role'=>'writer'];
        $html = $this->call('index');
        $this->assertStringContainsString('class="main media-workspace"', $html);
        $this->assertStringNotContainsString('Storage: Server', $html);
        $this->assertStringContainsString('id="serverStorageStatus" hidden', $html);
        $this->assertStringContainsString('id="mediaGridView"', $html);
        $this->assertStringContainsString('id="mediaListView"', $html);
        $this->assertStringContainsString('photo.jpg',$html);
        $this->assertStringContainsString('Browse media',$html);
        $this->assertStringNotContainsString('id="serverLoadingProgress"', $html);
        $this->assertStringNotContainsString('data-bulk-action="trash"', $html);
        $this->assertStringNotContainsString('Go to Local Library',$html);
        $this->assertStringNotContainsString('data-ajax-live-search',$html);
        $this->assertStringContainsString('All file types',$html);
        $this->assertStringContainsString('Apply filters',$html);
        $this->assertStringContainsString('Choose a suggestion, press Enter, or click Apply filters',$html);
        $this->assertStringNotContainsString('id="serverSendButton"',$html);
        $legacyUrl = $this->call('index',['page'=>99,'type'=>'video']);
        $this->assertStringNotContainsString('photo.jpg',$legacyUrl);
        $this->assertStringNotContainsString('Server Library pages',$legacyUrl);
        $filtered = $this->call('index',['q'=>'missing']);
        $this->assertStringNotContainsString('photo.jpg',$filtered);
        $this->assertStringContainsString('No matching folders or media found.',$filtered);
        $this->assertSame(403,$this->call('send')->getStatusCode());
        rename($this->root,$this->root.'-offline');
        try {
            $html = $this->call('index');
            $this->assertStringContainsString('Server disconnected.', $html);
            $this->assertStringContainsString('id="serverStorageStatus" hidden', $html);
            $this->assertStringNotContainsString('photo.jpg',$html);
        } finally { rename($this->root.'-offline',$this->root); }
    }

    public function testUnfinishedSearchHidesNoticeAndEmptyLibraryMessage(): void
    {
        $this->actor = ['isLoggedIn'=>true, 'role'=>'writer'];
        $listing = $this->library->browse();
        $listing['search_incomplete'] = true;
        $this->library = $this->createMock(ServerLibraryService::class);
        $this->library->method('root')->willReturn($this->root);
        $this->library->method('browse')->willReturn($listing);
        $html = $this->call('index', ['q'=>'hara']);
        $this->assertStringNotContainsString('Catalog contents are incomplete', $html);
        $this->assertStringNotContainsString('No matching folders or media found.', $html);
        $this->assertStringNotContainsString('Your media library is empty', $html);
        $this->assertStringContainsString('data-search-pending="1"', $html);
        $this->assertStringContainsString('data-catalog="1"', $html);
        $this->assertStringNotContainsString('function continueSearch', $html);
    }

    public function testFailedSearchDoesNotClaimThereAreNoMatches(): void
    {
        $this->actor = ['isLoggedIn'=>true, 'role'=>'writer'];
        $this->library = $this->createMock(ServerLibraryService::class);
        $this->library->method('root')->willReturn($this->root);
        $this->library->method('browse')->willThrowException(new RuntimeException('Search is unavailable.'));
        $html = $this->call('index', ['q'=>'hara']);
        $this->assertStringContainsString('Search is unavailable.', $html);
        $this->assertStringNotContainsString('No matching folders or media found.', $html);
        $this->assertStringNotContainsString('Your media library is empty', $html);
    }

    public function testUnindexedFolderIsNotDisplayedAsAnEmptyLibrary(): void
    {
        $this->actor = ['isLoggedIn'=>true, 'role'=>'writer'];
        $this->library = $this->createMock(ServerLibraryService::class);
        $this->library->method('browse')->willReturn(['items'=>[], 'file_total'=>0, 'listing_ms'=>1,
            'search_incomplete'=>true, 'folder_index_pending'=>true]);
        $html = $this->call('index', ['folder'=>'Waiting']);
        $this->assertStringNotContainsString('Missing results do not mean this folder is empty', $html);
        $this->assertStringNotContainsString('Your media library is empty', $html);
        $this->assertStringNotContainsString('No matching folders or media found', $html);
    }
    public function testLocalStyleFormsUseServerPathsAndPreserveOriginals(): void
    {
        $this->actor = ['isLoggedIn'=>true, 'role'=>'admin', 'employee_id'=>7];
        if (DIRECTORY_SEPARATOR !== '\\') mkdir($this->root.'/Bad\\folder');
        $html = $this->call('index');
        $this->assertStringNotContainsString('Invalid server path.', $html);
        $this->assertStringContainsString('photo.jpg', $html);
        $token = bin2hex('photo.jpg');
        $hash = hash_file('sha256', $this->root.'/photo.jpg');
        $this->call('action', [], '', ['name'=>'Nested & Events'], ['create-folder']);
        $this->assertDirectoryExists($this->root.'/Nested & Events');
        $this->call('action', [], '', ['parent_id'=>'Nested & Events', 'name'=>'2026'], ['create-folder']);
        $this->assertDirectoryExists($this->root.'/Nested & Events/2026');
        $this->call('action', [], '', ['name'=>'2027'], ['rename', bin2hex('Nested & Events/2026')]);
        $this->assertDirectoryExists($this->root.'/Nested & Events/2027');
        $this->assertDirectoryDoesNotExist($this->root.'/Nested & Events/2026');
        $this->call('action', [], '', ['file_ids'=>[$token], 'tags'=>['Event', 'event']], ['tags']);
        $this->assertCount(1, (new \App\Services\ServerLibraryEditor($this->library))->state()['tags']['photo.jpg']);
        $download = $this->call('action', [], '', ['file_ids'=>[$token]], ['download']);
        $this->assertInstanceOf(\CodeIgniter\HTTP\DownloadResponse::class, $download);
        $this->assertSame($hash, hash_file('sha256', $this->root.'/photo.jpg'));
        $preview = $this->call('item', [], '', [], [$token, 'preview']);
        $this->assertInstanceOf(\App\Libraries\MediaStreamResponse::class, $preview);
        $this->assertSame(404, $this->call('item', [], '', [], [bin2hex('../outside.jpg'), 'preview'])->getStatusCode());
        $this->assertSame(404, $this->call('item', [], '', [], ['invalid', 'preview'])->getStatusCode());
        $this->actor['role'] = 'writer';
        $this->assertSame(403, $this->call('action', [], '', ['file_ids'=>[$token]], ['trash'])->getStatusCode());
        $this->assertSame(403, $this->call('watermarking')->getStatusCode());
        $this->actor['role'] = 'admin';
        $this->call('action', [], '', [], ['trash', $token]);
        $this->assertFileDoesNotExist($this->root.'/photo.jpg');
        $this->assertCount(1, (new \App\Services\ServerLibraryEditor($this->library))->state()['trash']);
    }

    public function testPagesKeepFoldersAndSearchNestedMedia(): void
    {
        mkdir($this->root.'/Events & 2026');
        file_put_contents($this->root.'/Events & 2026/opening.jpg', 'fixture');
        for ($i=0; $i<45; $i++) file_put_contents($this->root.'/item'.$i.'.txt', 'fixture');
        $page = $this->library->browse('', '', 0, null, '', 2);
        $this->assertSame(2, $page['pagination']['current_page']);
        $this->assertSame(20, count(array_filter($page['items'], static fn ($item) => !$item['directory'])));
        $this->assertSame(1, count(array_filter($page['items'], static fn ($item) => $item['directory'])));
        $last = $this->library->browse('', '', 0, null, '', 999);
        $this->assertSame(3, $last['pagination']['current_page']);
        $search = $this->library->browse('', 'opening', 0, null, 'jpg', 1);
        $this->assertSame(['Events & 2026/opening.jpg'], array_column($search['items'], 'path'));
        $this->actor['role'] = 'writer';
        $html = $this->call('index', ['folder'=>'Events & 2026']);
        $this->assertStringContainsString('value="Events &amp; 2026"', $html);
        $this->assertStringContainsString('data-media-id="'.bin2hex('Events & 2026/opening.jpg').'"', $html);
    }

    public function testServerWatermarkPageUsesSameControlsAndConfinedDownloads(): void
    {
        $this->actor = ['isLoggedIn'=>true, 'role'=>'admin', 'employee_id'=>7];
        $html = $this->call('watermarking', ['item'=>bin2hex('photo.jpg')]);
        $this->assertIsString($html);
        foreach (['id="bulkWatermarkForm"', 'workspace/server-library/watermarking/download', 'workspace/server-library/items/'.bin2hex('photo.jpg').'/preview'] as $control) {
            $this->assertTrue(str_contains($html, $control), 'Missing watermark control: '.$control);
        }
        $hash = hash_file('sha256', $this->root.'/photo.jpg');
        $logos = service('watermarking')->availableLogos();
        $this->assertNotEmpty($logos);
        $download = $this->call('watermarkDownload', [], '', ['file_ids'=>[bin2hex('photo.jpg')], 'logo_code'=>$logos[0]['code'], 'position'=>'bottom_right', 'opacity'=>65, 'resize_percent'=>100]);
        $this->assertInstanceOf(\CodeIgniter\HTTP\DownloadResponse::class, $download);
        $this->assertSame($hash, hash_file('sha256', $this->root.'/photo.jpg'));
    }

    public function testServerAndLocalRenderTheSameLayoutAndControls(): void
    {
        helper('dashboard');
        mkdir($this->root.'/Events');
        $listing = $this->library->browse('', '', 0, null, '', 1);
        $base = ['catalog_mode'=>false, 'search_incomplete'=>false, 'user'=>['isLoggedIn'=>true, 'role'=>'admin', 'employee_id'=>0], 'activePage'=>'file-management', 'title'=>'File Management',
            'folder'=>'', 'query'=>'', 'type'=>'', 'connected'=>true, 'writable'=>true, 'error'=>null, 'success'=>null,
            'canManage'=>true, 'canUpload'=>true, 'canSend'=>false, 'departments'=>[], 'duplicates'=>[], 'uploadMaxBytes'=>0];
        $data = \App\Services\ServerLibraryPresentation::data($listing + $base);
        $local = view('workspace/media', $data + $listing + $base, ['saveData'=>false]);
        $server = view('workspace/server_library', $listing + $base, ['saveData'=>false]);
        $this->assertStringNotContainsString('data-server-duplicate-queue', $server);
        $this->assertStringNotContainsString('duplicatesModal', $local);
        $this->assertStringContainsString('id="folderSelectionToolbar"', $server);
        $this->assertStringContainsString('id="folderActionModal"', $server);
        $this->assertStringNotContainsString('id="folderSelectionToolbar"', $local);
        $structure = static function (string $html): array {
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $xpath = new DOMXPath($dom);
            // Server storage identification is intentionally retained above the copied UI.
            foreach ($xpath->query('//*[@data-server-storage]') as $storageStatus) {
                $storageStatus->parentNode->removeChild($storageStatus);
            }
            // Server Library uses a queue-page link instead of the legacy duplicate modal.
            foreach ($xpath->query('//*[@id="duplicatesModal" or @id="uploadFolderHelp" or @data-bs-target="#duplicatesModal" or @data-server-duplicate-queue] | //fieldset[.//input[@name="duplicate_mode"]]') as $control) {
                $control->parentNode->removeChild($control);
            }
            // Bulk folder management is intentionally available only in Server Library.
            foreach ($xpath->query('//*[@id="folderSelectionToolbar" or @id="folderActionModal" or @data-folder-select]') as $control) {
                $control->parentNode->removeChild($control);
            }
            $result = [];
            foreach ($xpath->query('//*[@id="main"]//*[not(self::script)] | //div[contains(concat(" ", normalize-space(@class), " "), " modal ")]//*[not(self::script)]') as $element) {
                // Catalog-only wrappers/status support in-place updates; shared controls remain identical.
                if (in_array($element->getAttribute('id'), ['catalogLiveStatus', 'catalogFolders', 'catalogProgress', 'catalogPagination', 'serverWriteError'], true)) continue;
                if (in_array($element->getAttribute('id'), ['catalogFolderCount', 'catalogFileCount'], true)) $element->removeAttribute('id');
                $class = preg_replace('/\s*active\b/', '', $element->getAttribute('class'));
                $result[] = [$element->tagName, $element->getAttribute('id'), $class, $element->getAttribute('type')];
            }
            return $result;
        };
        $this->assertSame($structure($local), $structure($server));
    }

    public function testMatchingServerUploadIsSavedWithoutDuplicateQueue(): void
    {
        $this->actor = ['isLoggedIn'=>true, 'role'=>'admin', 'employee_id'=>7];
        $uploads = new class($this->library) extends \App\Services\ServerLibraryUploadService {
            protected function analyze(\CodeIgniter\HTTP\Files\UploadedFile $file, string $folder): array { return []; }
        };
        $upload = new \App\Libraries\AssembledUpload($this->root.'/photo.jpg', 'copy.jpg');
        $result = $uploads->receive($upload, ['folder_id'=>'', 'relative_path'=>'', 'duplicate_mode'=>'media_library', 'upload_batch_id'=>'batch0001'], $this->actor);
        $this->assertFalse($result['duplicate']);
        $this->assertSame(hash_file('sha256', $this->root.'/photo.jpg'), hash_file('sha256', $this->root.'/copy.jpg'));

    }

    public function testConnectionStatusReportsShareAndAccountWriteAccess(): void
    {
        $this->actor = ['isLoggedIn'=>true, 'role'=>'admin'];
        $status = json_decode($this->call('status')->getBody(), true);
        $this->assertTrue($status['connected']);
        $this->assertTrue($status['readable']);
        $this->assertTrue($status['writable']);
        $this->assertTrue($status['can_upload']);
        $this->assertTrue($status['can_manage']);
        $this->actor['role'] = 'writer';
        $status = json_decode($this->call('status')->getBody(), true);
        $this->assertTrue($status['writable']);
        $this->assertFalse($status['can_upload']);
        $this->assertFalse($status['can_manage']);
        $status = json_decode($this->call('status', ['folder'=>'../private'])->getBody(), true);
        $this->assertTrue($status['connected']);
        $this->assertFalse($status['readable']);
        $this->assertFalse($status['writable']);
        $this->assertFalse($status['can_upload']);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testFreshRequestLoadsTheFileSizeHelper(): void
    {
        $this->assertFalse(function_exists('format_bytes'));
        $this->actor = ['isLoggedIn'=>true, 'role'=>'writer'];
        $html = $this->call('index');
        $this->assertTrue(function_exists('format_bytes'));
        $this->assertStringContainsString('photo.jpg', $html);
        $this->assertStringContainsString(format_bytes(filesize($this->root.'/photo.jpg')), $html);
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testFreshWatermarkRequestLoadsTheFileSizeHelper(): void
    {
        $this->assertFalse(function_exists('format_bytes'));
        $this->actor = ['isLoggedIn'=>true, 'role'=>'admin', 'employee_id'=>7];
        $html = $this->call('watermarking', ['item'=>bin2hex('photo.jpg')]);
        $this->assertTrue(function_exists('format_bytes'));
        $this->assertIsString($html);
        $this->assertStringContainsString('photo.jpg', $html);
    }

}
