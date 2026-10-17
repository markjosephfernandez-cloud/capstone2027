<?php

use App\Database\Migrations\AddServerLibraryCatalog;
use App\Database\Migrations\AddServerLibraryQueuePriority;
use App\Services\ServerLibraryCatalog;
use App\Services\ServerLibraryIndexer;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Storage;

require_once APPPATH . 'Database/Migrations/2026-09-29-000001_AddServerLibraryCatalog.php';
require_once APPPATH . 'Database/Migrations/2026-09-30-000001_AddServerLibraryQueuePriority.php';

final class ServerLibraryCatalogTest extends CIUnitTestCase
{
    private string $root;
    private Storage $config;
    private $catalogDb;

    protected function setUp(): void
    {
        parent::setUp();
        $this->catalogDb = db_connect();
        $this->assertSame('SQLite3', $this->catalogDb->DBDriver);
        $this->assertSame(':memory:', $this->catalogDb->database);
        (new AddServerLibraryCatalog())->up();
        (new AddServerLibraryQueuePriority())->up();
        $this->catalogDb->resetDataCache();
        $this->root = sys_get_temp_dir() . '/catalog-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/Hara 2025', 0770, true);
        file_put_contents($this->root . '/Hara 2025/photo.jpg', 'fixture');
        $this->config = new class($this->root) extends Storage {
            public function __construct(private string $source) { parent::__construct(); }
            public function scanSourceRoot(): string { return $this->source; }
        };
    }

    protected function tearDown(): void
    {
        (new AddServerLibraryCatalog())->down();
        $this->catalogDb->resetDataCache();
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        @unlink(WRITEPATH . 'cache/server-index-' . hash('sha256', $this->root) . '.lock');
        parent::tearDown();
    }

    public function testMoveImmediatelyUpdatesBothParentListingsAndNestedPaths(): void
    {
        mkdir($this->root . '/Destination');
        mkdir($this->root . '/Hara 2025/sample/Nested', 0770, true);
        file_put_contents($this->root . '/Hara 2025/sample/Nested/image.jpg', 'original');
        (new ServerLibraryIndexer($this->catalogDb, $this->config))->run();
        $library = new \App\Services\ServerLibraryService($this->config);
        $editor = new \App\Services\ServerLibraryEditor($library, $this->config);
        $editor->move(['Hara 2025/sample'], 'Destination');
        $catalog = new ServerLibraryCatalog($this->config, $this->catalogDb);
        $this->assertNotContains('sample', array_column($catalog->browse('Hara 2025')['items'], 'name'));
        $this->assertContains('sample', array_column($catalog->browse('Destination')['items'], 'name'));
        $this->assertSame('Destination/sample/Nested/image.jpg', $catalog->browse('Destination/sample/Nested')['items'][0]['path']);
        $this->assertFileExists($this->root . '/Destination/sample/Nested/image.jpg');
    }

    public function testFinderIconMetadataIsExcludedButOrdinaryExtensionlessFilesRemain(): void
    {
        file_put_contents($this->root . '/Hara 2025/' . "Icon\r", '');
        file_put_contents($this->root . '/Hara 2025/Icon', 'ordinary file');
        file_put_contents($this->root . '/Hara 2025/notes', 'no extension');
        (new ServerLibraryIndexer($this->catalogDb, $this->config))->run();
        $catalog = new ServerLibraryCatalog($this->config, $this->catalogDb);
        $this->assertSame(0, $this->catalogDb->table('server_library_index')->where('name', "Icon\r")->countAllResults());
        // Reproduce a previously indexed metadata row: it must disappear immediately.
        $row = $this->catalogDb->table('server_library_index')->where('name', 'Icon')->get()->getRowArray();
        unset($row['id']);
        $row['name'] = "Icon\r";
        $row['path'] = 'Hara 2025/' . "Icon\r";
        $row['path_hash'] = hash('sha256', $row['path']);
        $row['size'] = 0;
        $this->catalogDb->table('server_library_index')->insert($row);
        $listing = $catalog->browse('Hara 2025');
        $this->assertSame(3, $listing['file_total']);
        $this->assertSame(['Icon', 'notes', 'photo.jpg'], array_column($listing['items'], 'name'));
        $this->assertSame(['files'=>3, 'folders'=>0], $catalog->folderCounts('Hara 2025'));
        $this->assertSame(['Icon'], array_column($catalog->browse('', 'Icon')['items'], 'name'));
        $this->assertSame(['Icon'], array_column($catalog->suggestions('', 'Icon'), 'value'));
        $library = new \App\Services\ServerLibraryService($this->config);
        $this->assertSame(['Icon', 'notes', 'photo.jpg'], array_column($library->browse('Hara 2025')['items'], 'name'));
        $this->assertSame(['files'=>3, 'folders'=>0], $library->folderCounts('Hara 2025'));
        $this->assertFileExists($this->root . '/Hara 2025/' . "Icon\r");
    }

    public function testFinalCutCachesAreHiddenWhileOriginalVideosRemainVisible(): void
    {
        $event = 'Dal-uy.fcpbundle/Event';
        $original = $event . '/Original Media/clip.mov';
        $cache = $event . '/Render Files/High Quality Media/Frame 0 - 1023';
        foreach ([$original, $cache, $event . '/Analysis Files/Frame 1024 - 2047', 'Render Files/Frame 0 - 1023.mov'] as $path) {
            mkdir(dirname($this->root . '/' . $path), 0770, true);
            file_put_contents($this->root . '/' . $path, 'fixture');
        }
        (new ServerLibraryIndexer($this->catalogDb, $this->config))->run();
        $this->assertSame(0, $this->catalogDb->table('server_library_index')->where('path', $cache)->countAllResults());
        // Simulate stale rows from an older index, including case variants.
        $row = $this->catalogDb->table('server_library_index')->where('path', $original)->get()->getRowArray();
        unset($row['id']);
        foreach ([$cache, $event . '/ANALYSIS FILES/Frame 1024 - 2047', $event . '/Render Files', 'Dal-uy.fcpbundle/Render Files/Frame 0 - 1023'] as $path) {
            $row['path'] = $path;
            $row['name'] = basename($path);
            $row['path_hash'] = hash('sha256', $path);
            $row['parent_hash'] = hash('sha256', dirname($path));
            $row['is_dir'] = $path === $event . '/Render Files' ? 1 : 0;
            $this->catalogDb->table('server_library_index')->insert($row);
        }
        $catalog = new ServerLibraryCatalog($this->config, $this->catalogDb);
        $results = $catalog->browse('', 'Dal-uy', 0, null, '', 1);
        $this->assertSame(1, $results['file_total']);
        $this->assertContains($original, array_column($results['items'], 'path'));
        $this->assertSame([], $catalog->suggestions('', 'Frame 1024'));
        $this->assertSame(['Original Media'], array_column($catalog->browse($event)['items'], 'name'));
        $this->assertSame(['files'=>0, 'folders'=>1], $catalog->folderCounts($event));
        $this->assertSame(['Original Media'], array_column($catalog->listFolders($event), 'name'));
        $this->assertSame(['Frame 0 - 1023.mov'], array_column($catalog->browse('Render Files')['items'], 'name'));
        $library = new \App\Services\ServerLibraryService($this->config);
        $this->assertSame(['Original Media'], array_column($library->browse($event)['items'], 'name'));
        $this->assertSame('video', $catalog->browse($event . '/Original Media')['items'][0]['kind']);
        $this->assertFileExists($this->root . '/' . $cache);
    }

    public function testFolderPickerIncludesFoldersBeyondTheFirstGridPage(): void
    {
        for ($index = 1; $index <= 45; $index++) mkdir($this->root . '/Folder ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT));
        (new ServerLibraryIndexer($this->catalogDb, $this->config))->run();
        $catalog = new ServerLibraryCatalog($this->config, $this->catalogDb);
        $this->assertCount(30, $catalog->browse()['items']);
        $folders = $catalog->listFolders();
        $this->assertCount(46, $folders);
        $this->assertContains('Folder 45', array_column($folders, 'path'));
        $this->assertSame([], $catalog->listFolders('Hara 2025'));
    }

    public function testAdminSearchMatchesFolderNamesAndOnlyTaggedImages(): void
    {
        mkdir($this->root . '/Graduation');
        mkdir($this->root . '/Graduation/Unrelated child');
        foreach (['tagged.jpg', 'untagged.jpg', 'Graduation.jpg', 'other.jpg', 'clip.mov', 'notes.txt'] as $name) {
            file_put_contents($this->root . '/Graduation/' . $name, 'fixture');
        }
        (new ServerLibraryIndexer($this->catalogDb, $this->config))->run();
        foreach (['tagged.jpg', 'clip.mov', 'notes.txt'] as $name) {
            $this->catalogDb->table('server_library_index')->where('name', $name)->update(['tags'=>'["Graduation"]']);
        }
        $this->catalogDb->table('server_library_index')->where('name', 'other.jpg')->update(['tags'=>'["Portrait"]', 'classification'=>'Graduation']);
        $catalog = (new ServerLibraryCatalog($this->config, $this->catalogDb))->searchTaggedImagesOnly(true);
        $listing = $catalog->browse('', 'Graduation', 0, null, '', 1);
        $this->assertSame(['Graduation', 'tagged.jpg'], array_column($listing['items'], 'name'));
        $this->assertSame(1, $listing['file_total']);
        $this->assertSame(1, $listing['pagination']['total_results']);
        $this->assertSame(1, $listing['folder_pagination']['total_results']);
        $this->assertSame([], $catalog->browse('', 'clip')['items']);
        $this->assertSame([], $catalog->suggestions('', 'clip'));
        $this->assertSame([['value'=>'Graduation', 'kind'=>'Folder']], $catalog->suggestions('', 'Graduation'));
        $this->assertSame([['value'=>'Portrait', 'kind'=>'Tag']], $catalog->suggestions('', 'Portrait'));
        $this->assertSame(['Graduation'], array_column($catalog->browse('', 'Graduation', 0, null, 'mov')['items'], 'name'));
        $this->assertSame(6, $catalog->browse('Graduation')['file_total']);
        $this->assertGreaterThan(2, count($catalog->searchTaggedImagesOnly(false)->browse('', 'Graduation')['items']));
    }

    public function testSearchFoldersCannotPushImagesOffTheFirstPage(): void
    {
        for ($i = 0; $i < 45; $i++) {
            mkdir($this->root . '/Event ' . $i);
            file_put_contents($this->root . '/image-' . $i . '.jpg', 'fixture');
        }
        (new ServerLibraryIndexer($this->catalogDb, $this->config))->run();
        $this->catalogDb->table('server_library_index')->like('name', 'image-', 'after')->update(['tags'=>'["Event"]']);
        $catalog = (new ServerLibraryCatalog($this->config, $this->catalogDb))->searchTaggedImagesOnly(true);
        $first = $catalog->browse('', 'Event', 0, null, '', 1);
        $images = static fn ($listing) => array_column(array_filter($listing['items'], static fn ($item) => !$item['directory']), 'path');
        $folders = static fn ($listing) => array_column(array_filter($listing['items'], static fn ($item) => $item['directory']), 'path');
        $this->assertCount(40, $images($first));
        $this->assertCount(40, $folders($first));
        $this->assertSame(45, $first['pagination']['total_results']);
        $this->assertSame(45, $first['folder_pagination']['total_results']);
        $second = $catalog->browse('', 'Event', 0, null, '', 2);
        $this->assertCount(5, $images($second));
        $this->assertSame([], array_intersect($images($first), $images($second)));
        $this->assertSame($folders($first), $folders($second));
        $folderSecond = $catalog->searchTaggedImagesOnly(true, 2)->browse('', 'Event', 0, null, '', 1);
        $this->assertCount(5, $folders($folderSecond));
        $this->assertSame($images($first), $images($folderSecond));
        $last = $catalog->browse('', 'Event', 0, null, '', 999);
        $this->assertSame(2, $last['pagination']['current_page']);
        $this->assertCount(5, $images($last));
    }

    public function testRenameKeepsCachedDescendantsImmediatelyVisible(): void
    {
        (new ServerLibraryIndexer($this->catalogDb,$this->config))->run();
        $library = new \App\Services\ServerLibraryService($this->config);
        (new \App\Services\ServerLibraryEditor($library,$this->config))->rename('Hara 2025','Renamed event');
        $catalog = new ServerLibraryCatalog($this->config,$this->catalogDb);
        $items = $catalog->browse('Renamed event')['items'];
        $this->assertSame(['Renamed event/photo.jpg'],array_column($items,'path'));
        $this->assertSame(['files'=>1,'folders'=>0],$catalog->folderCounts('Renamed event'));
        $this->assertSame(0,$this->catalogDb->table('server_library_index')->like('path','Hara 2025','after')->countAllResults());
    }

    public function testNewFoldersAndNestedUploadsAppearWithoutAnotherScan(): void
    {
        (new ServerLibraryIndexer($this->catalogDb, $this->config))->run();
        $library = new \App\Services\ServerLibraryService($this->config);
        $editor = new \App\Services\ServerLibraryEditor($library, $this->config);
        $editor->createFolder('', 'carl Patula');
        $catalog = new ServerLibraryCatalog($this->config, $this->catalogDb);
        $this->assertContains('carl Patula', array_column($catalog->browse()['items'], 'path'));
        $editor->createFolder('carl Patula', 'New event');
        $this->assertSame(['carl Patula/New event'], array_column($catalog->browse('carl Patula')['items'], 'path'));
        $uploads = new class($library, $this->config) extends \App\Services\ServerLibraryUploadService {
            protected function analyze(\CodeIgniter\HTTP\Files\UploadedFile $file, string $folder): array { return ['event_name'=>'New event']; }
        };
        $image = imagecreatetruecolor(10, 10);
        $temporary = $this->root . '/fixture.png';
        imagepng($image, $temporary); imagedestroy($image);
        $result = $uploads->receive(new \App\Libraries\AssembledUpload($temporary, 'photo.png'),
            ['folder_id'=>'carl Patula/New event', 'relative_path'=>'Nested/photo.png', 'upload_batch_id'=>'batch0001'], []);
        $this->assertTrue($result['ok']);
        $this->assertSame(['carl Patula/New event/Nested'], array_column($catalog->browse('carl Patula/New event')['items'], 'path'));
        $items = $catalog->browse('carl Patula/New event/Nested')['items'];
        $this->assertSame([$result['path']], array_column($items, 'path'));
        $this->assertSame('New event', $items[0]['classification']);
        $this->assertSame(['files'=>1, 'folders'=>0], $catalog->folderCounts('carl Patula/New event/Nested'));
    }

    public function testCatalogSearchAndFolderBrowsingWorkWithNasOffline(): void
    {
        $indexer = new ServerLibraryIndexer($this->catalogDb, $this->config);
        $this->assertSame('complete', $indexer->run()['status']);
        rename($this->root, $this->root . '-offline');
        try {
            $catalog = new ServerLibraryCatalog($this->config, $this->catalogDb);
            $search = $catalog->browse('', 'hara', 0, null, '', 1);
            $this->assertCount(2, $search['items']);
            $this->assertFalse($search['search_incomplete']);
            $this->assertSame(['Hara 2025/photo.jpg'], array_column($catalog->browse('Hara 2025')['items'], 'path'));
            $this->assertSame(['files'=>1,'folders'=>0], $catalog->folderCounts('Hara 2025'));
            $this->assertSame([], $catalog->browse('', '%', 0, null, '', 1)['items'], 'LIKE wildcards must be escaped.');
        } finally { rename($this->root . '-offline', $this->root); }
    }

    public function testSearchCountsPaginationAndLiteralCharacters(): void
    {
        for ($i = 0; $i < 45; $i++) file_put_contents($this->root . '/Hara 2025/photo-' . $i . '.jpg', 'fixture');
        file_put_contents($this->root . '/100%_done!.jpg', 'fixture');
        (new ServerLibraryIndexer($this->catalogDb, $this->config))->run();
        $catalog = new ServerLibraryCatalog($this->config, $this->catalogDb);
        $page = $catalog->browse('', 'hara', 0, null, '', 99);
        $this->assertSame(47, $page['pagination']['total_results']);
        $this->assertSame(46, $page['file_total']);
        $this->assertSame(2, $page['pagination']['current_page']);
        $this->assertCount(7, $page['items']);
        foreach (['%', '_', '!', '%_done!'] as $query) {
            $this->assertSame(['100%_done!.jpg'], array_column($catalog->browse('', $query, 0, null, '', 1)['items'], 'name'));
        }
        $empty = $catalog->browse('', 'no matching filename', 0, null, '', 1);
        $this->assertSame(0, $empty['file_total']);
        $this->assertSame(0, $empty['pagination']['total_results']);
    }

    public function testCatalogSuggestionsFindActualValuesBeyondMatchingParentPaths(): void
    {
        for ($i = 0; $i < 45; $i++) file_put_contents($this->root . '/Hara 2025/child-' . $i . '.jpg', 'fixture');
        file_put_contents($this->root . '/Hara 2025/zz Hara.jpg', 'fixture');
        (new ServerLibraryIndexer($this->catalogDb, $this->config))->run();
        $catalog = new ServerLibraryCatalog($this->config, $this->catalogDb);
        $this->catalogDb->table('server_library_index')->where('name', 'zz Hara.jpg')
            ->update(['tags'=>'["Hara tag"]', 'classification'=>'Hara event', 'date_taken'=>'2026-10-01']);
        rename($this->root, $this->root . '-offline');
        try {
            $values = array_column($catalog->suggestions('', 'hara', 'jpg'), 'value');
            foreach (['Hara 2025', 'zz Hara.jpg', 'Hara tag', 'Hara event'] as $value) $this->assertContains($value, $values);
            $this->assertSame(['Hara 2025'], array_column($catalog->suggestions('', 'hara', 'png'), 'value'));
            $this->assertSame(['2026-10-01'], array_column($catalog->suggestions('', '2026-10'), 'value'));
            $this->assertSame([], $catalog->suggestions('', 'no matching filename'));
            $this->assertSame([], $catalog->suggestions('', 'h'));
            $this->assertLessThanOrEqual(8, count($catalog->suggestions('', 'child')));
        } finally { rename($this->root . '-offline', $this->root); }
    }

    private function measuredIndexer(): ServerLibraryIndexer
    {
        return new class($this->catalogDb, $this->config) extends ServerLibraryIndexer {
            public int $reads = 0;
            public array $handles = [];
            protected function openDirectory(string $directory)
            {
                $handle = parent::openDirectory($directory);
                $this->handles[] = $handle;
                return $handle;
            }
            protected function readDirectory($handle): string|false
            {
                $this->reads++;
                return parent::readDirectory($handle);
            }
        };
    }

    public function testLargeDirectoryIsReadOnceAcrossMultipleCheckpoints(): void
    {
        for ($i = 0; $i < 350; $i++) file_put_contents($this->root . '/' . $i . '.txt', 'x');
        $indexer = $this->measuredIndexer();
        $this->assertSame('complete', $indexer->run()['status']);
        $this->assertSame(352, $this->catalogDb->table('server_library_index')->countAllResults());
        $this->assertCount(2, $indexer->handles, 'Each directory should be opened only once.');
        $this->assertLessThanOrEqual(370, $indexer->reads, 'Reads should grow with entries, not with repeated offset replays.');
        foreach ($indexer->handles as $handle) $this->assertFalse(is_resource($handle), 'Completed runs must close directory handles.');
    }

    public function testLargeDirectoryResumeReplaysCheckpointOnceAndClosesPausedHandle(): void
    {
        for ($i = 0; $i < 350; $i++) file_put_contents($this->root . '/' . $i . '.txt', 'x');
        $indexer = $this->measuredIndexer();
        $result = $indexer->run(0, false, fn () => $this->catalogDb->table('server_library_index')->countAllResults() < 100);
        $this->assertSame('paused', $result['status']);
        $cursor = $this->catalogDb->table('server_library_scan_queue')->where('path', '')->get()->getRowArray();
        $this->assertNotNull($cursor);
        $this->assertGreaterThanOrEqual(100, (int)$cursor['entry_offset']);
        foreach ($indexer->handles as $handle) $this->assertFalse(is_resource($handle));
        $indexer->reads = 0;
        $this->assertSame('complete', $indexer->run()['status']);
        $this->assertSame(352, $this->catalogDb->table('server_library_index')->countAllResults());
        $this->assertLessThanOrEqual(370, $indexer->reads, 'Resume should replay the offset only once.');
        foreach ($indexer->handles as $handle) $this->assertFalse(is_resource($handle));
    }

    public function testInterruptedScanResumesAndFailedScanNeverDeletesEntries(): void
    {
        for ($i = 0; $i < 25; $i++) file_put_contents($this->root . '/Hara 2025/' . $i . '.txt', 'x');
        $indexer = new ServerLibraryIndexer($this->catalogDb, $this->config);
        $checks = 0;
        $this->assertSame('paused', $indexer->run(0, false, static function () use (&$checks) { return ++$checks < 12; })['status']);
        $this->assertSame('complete', $indexer->run()['status']);
        $this->assertSame(27, $this->catalogDb->table('server_library_index')->countAllResults());
        unlink($this->root . '/Hara 2025/0.txt');
        rename($this->root, $this->root . '-offline');
        try { $indexer->run(); $this->fail('Disconnected share must fail.'); }
        catch (RuntimeException) { $this->assertSame(27, $this->catalogDb->table('server_library_index')->countAllResults()); }
        finally { rename($this->root . '-offline', $this->root); }
        $indexer->run();
        $this->assertSame(26, $this->catalogDb->table('server_library_index')->countAllResults());
    }

    public function testCatalogExcludesSymlinksAndPrivateFilesAndPaginates(): void
    {
        mkdir($this->root . '/.private');
        file_put_contents($this->root . '/.private/secret', 'private');
        symlink($this->root . '/Hara 2025', $this->root . '/linked');
        for ($i = 0; $i < 55; $i++) file_put_contents($this->root . '/Hara 2025/' . $i . '.txt', 'x');
        (new ServerLibraryIndexer($this->catalogDb, $this->config))->run();
        $catalog = new ServerLibraryCatalog($this->config, $this->catalogDb);
        $this->assertCount(1, $catalog->browse()['items']);
        $this->assertCount(40, $catalog->browse('Hara 2025', '', 0, null, '', 1)['items']);
        $this->assertCount(16, $catalog->browse('Hara 2025', '', 0, null, '', 2)['items']);
        $this->assertCount(1, $catalog->browse('Hara 2025', '', 0, null, 'jpg', 1)['items']);
    }

    public function testControllerRendersCatalogWithoutAnyLiveLibraryAccess(): void
    {
        (new ServerLibraryIndexer($this->catalogDb, $this->config))->run();
        $catalog = new ServerLibraryCatalog($this->config, $this->catalogDb);
        $session = $this->createMock(\CodeIgniter\Session\Session::class);
        $actor = ['isLoggedIn'=>true, 'role'=>'admin'];
        $session->method('get')->willReturnCallback(static fn ($key = null) => $key === null ? $actor : ($actor[$key] ?? null));
        \Config\Services::injectMock('session', $session);
        $controller = new class($catalog) extends \App\Controllers\Web\ServerLibraryController {
            public function __construct(private ServerLibraryCatalog $testCatalog) {}
            protected function catalog(): \App\Services\ServerLibraryService { return $this->testCatalog; }
            protected function library(): \App\Services\ServerLibraryService { throw new RuntimeException('A live NAS read was attempted.'); }
        };
        $request = new \CodeIgniter\HTTP\IncomingRequest(config('App'), new \CodeIgniter\HTTP\URI('http://example.com/'), null, new \CodeIgniter\HTTP\UserAgent());
        $request->setGlobal('get', ['q'=>'hara']);
        $controller->initController($request, new \CodeIgniter\HTTP\Response(config('App')), service('logger'));
        $html = $controller->index();
        $this->assertStringContainsString('Hara 2025', $html);
        $this->assertStringNotContainsString('Refresh results', $html);
        $this->assertStringContainsString('id="catalogLiveStatus" hidden', $html);
        $this->assertStringNotContainsString('function continueSearch', $html);
        $this->assertStringContainsString('data-catalog="1"', $html);
        \Config\Services::resetSingle('session');
    }

    public function testUnavailableQueuedDirectoryPreservesCatalogUntilSuccessfulRestart(): void
    {
        $indexer = new ServerLibraryIndexer($this->catalogDb, $this->config);
        $indexer->run();
        $checks = 0;
        $indexer->run(0, true, static function () use (&$checks) { return ++$checks < 5; });
        unlink($this->root . '/Hara 2025/photo.jpg');
        rmdir($this->root . '/Hara 2025');
        $this->assertSame('partial', $indexer->run()['status']);
        $this->assertSame(2, $this->catalogDb->table('server_library_index')->countAllResults());
        $this->assertSame('complete', $indexer->run(0, true)['status']);
        $this->assertSame(0, $this->catalogDb->table('server_library_index')->countAllResults());
    }

    public function testConcurrentWorkerIsRejectedWithoutChangingCatalog(): void
    {
        $path = WRITEPATH . 'cache/server-index-' . hash('sha256', $this->root) . '.lock';
        $lock = fopen($path, 'c');
        flock($lock, LOCK_EX);
        try {
            (new ServerLibraryIndexer($this->catalogDb, $this->config))->run();
            $this->fail('A second worker must not run.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('already running', $error->getMessage());
            $this->assertSame(0, $this->catalogDb->table('server_library_index')->countAllResults());
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }

    public function testUnreadableFolderDoesNotBlockOthersAndRetryKeepsProgress(): void
    {
        mkdir($this->root . '/Next');
        $indexer = new ServerLibraryIndexer($this->catalogDb, $this->config);
        $indexer->run();
        $indexer->run(0, true, fn () => $this->catalogDb->table('server_library_scan_queue')->where('path', '')->countAllResults() > 0);
        $run = $this->catalogDb->table('server_library_sources')->get()->getRowArray()['run_id'];
        unlink($this->root . '/Hara 2025/photo.jpg');
        rmdir($this->root . '/Hara 2025');
        file_put_contents($this->root . '/Next/new.jpg', 'new');
        $this->assertSame('partial', $indexer->run()['status']);
        $catalog = new ServerLibraryCatalog($this->config, $this->catalogDb);
        $this->assertSame(['Next/new.jpg'], array_column($catalog->browse('Next')['items'], 'path'));
        $this->assertTrue($catalog->browse('Hara 2025')['folder_index_pending']);
        $this->assertSame(1, $this->catalogDb->table('server_library_scan_queue')->countAllResults());
        mkdir($this->root . '/Hara 2025');
        file_put_contents($this->root . '/Hara 2025/recovered.jpg', 'recovered');
        $this->assertSame('complete', $indexer->run()['status']);
        $this->assertNotSame($run, $this->catalogDb->table('server_library_sources')->get()->getRowArray()['run_id']);
        $this->assertSame(['Hara 2025/recovered.jpg'], array_column($catalog->browse('Hara 2025')['items'], 'path'));
    }
    public function testTrashRemovesCatalogEntriesAndRestoreQueuesTheOriginalFolder(): void
    {
        (new ServerLibraryIndexer($this->catalogDb, $this->config))->run();
        $editor = new \App\Services\ServerLibraryEditor(new \App\Services\ServerLibraryService($this->config), $this->config);
        $editor->trash(['Hara 2025/photo.jpg'], 7);
        $catalog = new ServerLibraryCatalog($this->config, $this->catalogDb);
        $this->assertSame([], $catalog->browse('Hara 2025')['items']);
        $editor->restore(array_key_first($editor->state()['trash']));
        $this->assertTrue($catalog->browse('Hara 2025')['folder_index_pending']);
        (new ServerLibraryIndexer($this->catalogDb, $this->config))->run();
        $this->assertCount(1, $catalog->browse('Hara 2025')['items']);
    }

    public function testReservedTrashIsNotIndexedAndOldRecordsAreHiddenImmediately(): void
    {
        mkdir($this->root . '/TRASH');
        file_put_contents($this->root . '/TRASH/deleted.jpg', 'deleted');
        $indexer = new ServerLibraryIndexer($this->catalogDb, $this->config);
        $indexer->run();
        $catalog = new ServerLibraryCatalog($this->config, $this->catalogDb);
        $this->assertSame([], $catalog->browse('', 'deleted')['items']);
        $row = $this->catalogDb->table('server_library_index')->get(1)->getRowArray();
        unset($row['id']);
        $row['path'] = 'TRASH'; $row['name'] = 'TRASH'; $row['path_hash'] = hash('sha256','TRASH'); $row['parent_hash'] = hash('sha256','');
        $this->catalogDb->table('server_library_index')->insert($row);
        $this->assertSame([], $catalog->browse('', 'trash')['items']);
        $this->assertSame(['files'=>0,'folders'=>1], $catalog->folderCounts(''));
        $indexer->run();
        $this->assertSame(0, $this->catalogDb->table('server_library_index')->where('path','TRASH')->countAllResults());
    }

    public function testOpenedFolderIsIndexedBeforeOlderQueuedFolders(): void
    {
        mkdir($this->root . '/Events');
        file_put_contents($this->root . '/Events/event.jpg', 'fixture');
        $indexer = new ServerLibraryIndexer($this->catalogDb, $this->config);
        $indexer->run(0, false, fn () => $this->catalogDb->table('server_library_scan_queue')->where('path', '')->countAllResults() > 0);
        // Explicitly put Events behind another folder regardless of directory order.
        $this->catalogDb->table('server_library_scan_queue')->where('path', 'Events')->delete();
        $catalog = new ServerLibraryCatalog($this->config, $this->catalogDb);
        $this->catalogDb->table('server_library_scan_queue')->insert([
            'source_key'=>$catalog->sourceKey, 'path'=>'Events', 'path_hash'=>hash('sha256', 'Events'), 'entry_offset'=>0,
        ]);
        $this->assertTrue($catalog->browse('Events')['folder_index_pending']);
        $indexer->run(0, false, fn () => $this->catalogDb->table('server_library_scan_queue')->where('path', 'Events')->countAllResults() > 0);
        $this->assertSame(['Events/event.jpg'], array_column($catalog->browse('Events')['items'], 'path'));
        $this->assertSame(1, $this->catalogDb->table('server_library_scan_queue')->where('path', 'Hara 2025')->countAllResults());
        $this->assertFalse($catalog->browse('Events')['folder_index_pending']);
    }

}
