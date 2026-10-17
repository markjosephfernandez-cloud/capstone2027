<?php

use App\Services\ServerLibraryService;
use App\Services\ServerLibraryTransferService;
use App\Services\StorageService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Storage;

final class ServerLibraryTest extends CIUnitTestCase
{
    private string $root;
    private ServerLibraryService $library;
    private StorageService $storage;
    private array $cachedCounts = [];
    private array $cacheTtls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/server-library-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/share/Album',0775,true);
        $config = new class extends Storage {
            public string $testRoot;
            public function scanSourceRoot(): string { return $this->testRoot.'/share'; }
            public function root(): string { return $this->testRoot.'/local'; }
            public function departmentRoot(): string { return $this->testRoot.'/departments'; }
            public function legacyRoots(): array { return []; }
        };
        $config->testRoot = $this->root;
        $cache = $this->createMock(\CodeIgniter\Cache\CacheInterface::class);
        $cache->method('get')->willReturnCallback(fn ($key) => $this->cachedCounts[$key] ?? null);
        $cache->method('save')->willReturnCallback(function ($key,$value,$ttl) { $this->cachedCounts[$key]=$value; $this->cacheTtls[]=$ttl; return true; });
        $cache->method('delete')->willReturnCallback(function ($key) { unset($this->cachedCounts[$key]); return true; });
        $this->library = new ServerLibraryService($config,$cache);
        $this->storage = new StorageService($config);
        for ($n=1;$n<=45;$n++) file_put_contents($this->root.'/share/Album/file'.$n.'.txt','sample-'.$n);
        $image = imagecreatetruecolor(8,4);
        imagejpeg($image,$this->root.'/share/photo.jpg'); imagedestroy($image);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->root);
        parent::tearDown();
    }

    public function testListsFileBatchesWithoutImportingOrChangingSources(): void
    {
        $before = $this->snapshot();
        $this->assertSame(['Album','photo.jpg'],array_column($this->library->browse()['items'],'name'));
        $result = $this->library->browse('Album');
        $this->assertSame(array_map(static fn ($n) => 'file'.$n.'.txt', range(1,30)),array_column($result['items'],'name'));
        $this->assertSame(45,$result['file_total']);
        $this->assertSame(30,$result['next_offset']);
        $last = $this->library->browse('Album','',30);
        $this->assertSame(array_map(static fn ($n) => 'file'.$n.'.txt', range(31,45)),array_column($last['items'],'name'));
        $this->assertNull($last['next_offset']);
        $this->assertSame([],$this->cachedCounts);
        $this->assertCount(1,$this->library->browse('Album','file45')['items']);
        $this->assertSame($before,$this->snapshot());
        $this->assertDirectoryDoesNotExist($this->root.'/local');
    }

    public function testInterruptedReadsLeaveSourcesUnchangedAndDoNotCachePartialCounts(): void
    {
        $before = $this->snapshot();
        foreach (['browse', 'folderCounts'] as $method) {
            $checks = 0;
            $continue = static function () use (&$checks): bool { return ++$checks < 12; };
            try {
                if ($method === 'browse') $this->library->browse('Album', '', 0, $continue);
                else $this->library->folderCounts('Album', $continue);
                $this->fail('Interrupted reads must not return incomplete results.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('reading stopped', $exception->getMessage());
            }
            $this->assertSame([], $this->cachedCounts);
            $this->assertSame($before, $this->snapshot());
        }
        $this->assertSame(['files'=>45, 'folders'=>0], $this->library->folderCounts('Album'));
        $this->assertSame(45, $this->library->browse('Album')['file_total']);
        $this->assertCount(1, $this->cachedCounts);
    }

    public function testSearchResumesUntilNestedFolderMatchesAndReusesInventory(): void
    {
        mkdir($this->root . '/share/Album/Hara celebration');
        file_put_contents($this->root . '/share/Album/Hara celebration/photo.txt', 'fixture');
        $incomplete = false;
        for ($attempt = 0; $attempt < 30; $attempt++) {
            $checks = 0;
            $result = $this->library->browse('', 'hara', 0, static function () use (&$checks) { return ++$checks < 12; }, '', 1);
            $incomplete = $incomplete || $result['search_incomplete'];
            if (!$result['search_incomplete']) break;
        }
        $this->assertTrue($incomplete, 'The inventory must span multiple requests.');
        $this->assertFalse($result['search_incomplete']);
        $this->assertContains('Album/Hara celebration', array_column($result['items'], 'path'));
        $this->assertContains('Album/Hara celebration/photo.txt', array_column($result['items'], 'path'));
        $checks = 0;
        $cached = $this->library->browse('', 'HARA', 0, static function () use (&$checks) { return ++$checks < 4; }, '', 1);
        $this->assertSame($result['items'], $cached['items']);
        rename($this->root . '/share', $this->root . '/offline');
        try {
            $this->expectException(RuntimeException::class);
            $this->library->browse('', 'Hara', 0, null, '', 1);
        } finally { rename($this->root . '/offline', $this->root . '/share'); }
    }

    public function testSearchIndexExcludesPrivateAndLinkedFoldersAndInvalidatesOnEdits(): void
    {
        mkdir($this->root . '/share/.hidden');
        file_put_contents($this->root . '/share/.hidden/hara.txt', 'private');
        mkdir($this->root . '/outside');
        file_put_contents($this->root . '/outside/hara.txt', 'outside');
        symlink($this->root . '/outside', $this->root . '/share/Hara link');
        $this->assertSame([], $this->library->browse('', 'hara', 0, null, '', 1)['items']);
        mkdir($this->root . '/share/Hara new');
        $this->library->invalidateCounts('');
        $this->assertSame(['Hara new'], array_column($this->library->browse('', 'hara', 0, null, '', 1)['items'], 'path'));
    }

    public function testRejectsTraversalAbsoluteHiddenAndSymlinkPaths(): void
    {
        file_put_contents($this->root.'/secret.txt','private');
        symlink($this->root.'/secret.txt',$this->root.'/share/link.txt');
        symlink($this->root.'/share/Album',$this->root.'/share/alias');
        foreach (['../secret.txt','Album/../../secret.txt','/etc/passwd','C:/private','Album\\file1.txt','.hidden','link.txt','alias/file1.txt'] as $path) {
            $rejected = false;
            try { $this->library->resolve($path); } catch (RuntimeException) { $rejected = true; }
            $this->assertTrue($rejected,$path);
        }
        $this->assertSame(['Album','photo.jpg'],array_column($this->library->browse()['items'],'name'));
    }

    public function testAllFoldersPrecedeLooseFilesAndSearchMatchesBoth(): void
    {
        for ($n=1;$n<=25;$n++) mkdir($this->root.'/share/Album/Folder'.$n);
        file_put_contents($this->root.'/share/Album/photo.JPG','image fixture');
        file_put_contents($this->root.'/share/Album/movie.MOV','video fixture');
        $items = $this->library->browse('Album')['items'];
        $this->assertCount(55,$items);
        $this->assertSame(array_map(static fn ($n) => 'Folder'.$n, range(1,25)),array_column(array_slice($items,0,25),'name'));
        $remaining = $this->library->browse('Album','',30);
        $this->assertCount(17,$remaining['items']);
        $this->assertSame('photo.JPG',$remaining['items'][16]['name']);
        $this->assertSame(['Folder25'],array_column($this->library->browse('Album','folder25')['items'],'name'));
        $this->assertSame(['photo.JPG'],array_column($this->library->browse('Album','PHOTO')['items'],'name'));
        $this->assertSame([],$this->library->browse('Album','missing')['items']);
    }

    public function testFolderCountsExcludeHiddenSystemAndLinkedChildren(): void
    {
        mkdir($this->root.'/share/Album/Nested');
        file_put_contents($this->root.'/share/Album/Nested/deeper.txt','nested');
        file_put_contents($this->root.'/share/Album/.hidden','hidden');
        file_put_contents($this->root.'/share/Album/Thumbs.db','system');
        symlink($this->root.'/share/photo.jpg',$this->root.'/share/Album/link.jpg');
        symlink($this->root.'/share/Album/Nested',$this->root.'/share/Album/alias');
        $this->assertSame(['files'=>45,'folders'=>1],$this->library->folderCounts('Album'));
    }

    public function testCountsAreCachedBrieflyButNeverBypassPathOrConnectionChecks(): void
    {
        $this->assertSame(['files'=>45,'folders'=>0],$this->library->folderCounts('Album'));
        file_put_contents($this->root.'/share/Album/new.txt','new');
        $this->assertSame(45,$this->library->folderCounts('Album')['files']);
        $this->assertSame([180],$this->cacheTtls);
        $this->cachedCounts = []; // Simulate cache expiry.
        $this->assertSame(46,$this->library->folderCounts('Album')['files']);
        rename($this->root.'/share/Album',$this->root.'/share/Original');
        symlink($this->root.'/share/Original',$this->root.'/share/Album');
        try {
            $this->library->folderCounts('Album');
            $this->fail('Cached counts must not allow a replaced symlink.');
        } catch (RuntimeException $error) { $this->assertStringContainsString('Linked',$error->getMessage()); }
        unlink($this->root.'/share/Album');
        rename($this->root.'/share/Original',$this->root.'/share/Album');
        rename($this->root.'/share',$this->root.'/offline');
        try {
            $this->library->folderCounts('Album');
            $this->fail('Cached counts must not be served while offline.');
        } catch (RuntimeException $error) { $this->assertStringContainsString('disconnected',$error->getMessage()); }
        finally { rename($this->root.'/offline',$this->root.'/share'); }
    }

    public function testDisconnectHidesFilesAndReconnectMakesThemAvailable(): void
    {
        $this->library->browse();
        rename($this->root.'/share',$this->root.'/disconnected');
        try {
            $failed = false;
            try { $this->library->browse(); } catch (RuntimeException $error) { $failed = true; $this->assertStringContainsString('disconnected',$error->getMessage()); }
            $this->assertTrue($failed);
            $this->assertDirectoryDoesNotExist($this->root.'/share');
        } finally { rename($this->root.'/disconnected',$this->root.'/share'); }
        $this->assertCount(2,$this->library->browse()['items']);
    }

    public function testExplicitTransferCopiesLocallyAndRollbackNeverChangesServer(): void
    {
        $db = db_connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        $db->query('CREATE TABLE user (role TEXT, department TEXT, is_active INTEGER)');
        $db->query('CREATE TABLE folder (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT,path TEXT,parent_id INTEGER,is_deleted INTEGER,created_at TEXT)');
        $db->query('CREATE TABLE files (id INTEGER PRIMARY KEY AUTOINCREMENT,filename TEXT,display_title TEXT,filepath TEXT,filetype TEXT,mime_type TEXT,media_kind TEXT,tags TEXT,size INTEGER,folder_id INTEGER,employee_id INTEGER,dateupload TEXT,is_deleted INTEGER,description TEXT,last_action TEXT,last_action_at TEXT)');
        $db->table('user')->insert(['role'=>'department','department'=>'CCS','is_active'=>1]);
        $transfer = new ServerLibraryTransferService($db,$this->library,$this->storage);
        $actor = ['role'=>'admin','employee_id'=>1];
        $before = $this->snapshot();
        try {
            $result = $transfer->send(['photo.jpg'],'CCS',$actor,function ($ids,$department) use ($db) {
                $file = $db->table('files')->where('id',$ids[0])->get()->getRowArray();
                $path = $this->storage->resolveDatabaseFile($file['filepath']);
                $this->assertSame(hash_file('sha256',$this->root.'/share/photo.jpg'),hash_file('sha256',$path));
                $this->assertSame('CCS',$department);
                $this->assertNull($file['folder_id']);
                return ['request_id'=>5,'file_count'=>count($ids)];
            });
            $this->assertSame(5,$result['request_id']);
            $failed = false;
            try { $transfer->send(['photo.jpg'],'CCS',$actor,static function () use ($db) { $db->transStart(); throw new RuntimeException('Simulated nested assignment failure'); }); }
            catch (RuntimeException) { $failed = true; }
            $this->assertTrue($failed);
            $this->assertSame(0,$db->transDepth);
            $this->assertSame(1,$db->table('files')->countAllResults());
            $this->assertSame(0,$db->table('folder')->countAllResults());
            $this->assertCount(1,glob($this->root.'/local/*/*.jpg'));
            $this->assertSame($before,$this->snapshot());
        } finally { $db->close(); }
    }

    public function testDistributionPickerBrowsesAndExpandsServerFoldersWithoutImporting(): void
    {
        $picker = new \App\Services\ServerDistributionPicker($this->library);
        $before = $this->snapshot();
        $listing = $picker->browse(bin2hex('Album'), '', 2);
        $this->assertCount(20, $listing['files']);
        $this->assertSame('Album', $listing['breadcrumbs'][1]['name']);
        $selection = $picker->selection([bin2hex('Album/file1.txt')], [bin2hex('Album')]);
        $this->assertCount(45, $selection['files']);
        $this->assertCount(1, $selection['folders']);
        $this->assertSame($before, $this->snapshot());
        $this->assertDirectoryDoesNotExist($this->root.'/local');
        $this->expectException(RuntimeException::class);
        $picker->selection([bin2hex('../secret.txt')], []);
    }

    public function testServerTransmissionPreservesFoldersAndRollsBackFailedAssignment(): void
    {
        mkdir($this->root.'/share/ACADEMIC YEAR/2026/Event/Nested', 0775, true);
        copy($this->root.'/share/photo.jpg', $this->root.'/share/ACADEMIC YEAR/2026/Event/Nested/photo.jpg');
        $before = $this->snapshot();
        $db = db_connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        $db->query('CREATE TABLE media_requests (id INTEGER PRIMARY KEY, status TEXT, department TEXT)');
        $db->query('CREATE TABLE folder (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT,path TEXT,parent_id INTEGER,is_deleted INTEGER,created_at TEXT)');
        $db->query('CREATE TABLE files (id INTEGER PRIMARY KEY AUTOINCREMENT,filename TEXT,display_title TEXT,filepath TEXT,filetype TEXT,mime_type TEXT,media_kind TEXT,tags TEXT,size INTEGER,folder_id INTEGER,employee_id INTEGER,dateupload TEXT,is_deleted INTEGER,description TEXT,last_action TEXT,last_action_at TEXT)');
        $db->table('media_requests')->insert(['id'=>42,'status'=>'submitted','department'=>'CCS']);
        $transfer = new ServerLibraryTransferService($db,$this->library,$this->storage);
        $actor = ['role'=>'admin','employee_id'=>1];
        try {
            $result = $transfer->transmit(['ACADEMIC YEAR/2026/Event/Nested/photo.jpg'], ['ACADEMIC YEAR/2026/Event'], 42, $actor, function ($ids,$folderIds,$department,$requestId) use ($db) {
                $this->assertSame('CCS',$department);
                $this->assertSame(42,$requestId);
                $this->assertCount(1,$folderIds);
                $folder = $db->table('folder')->where('id',$folderIds[0])->get()->getRowArray();
                $this->assertSame('Event',$folder['name']);
                $this->assertNull($folder['parent_id']);
                $file = $db->table('files')->where('id',$ids[0])->get()->getRowArray();
                $this->assertStringContainsString('/Event/Nested/', $file['filepath']);
                $this->assertSame(hash_file('sha256',$this->root.'/share/photo.jpg'),hash_file('sha256',$this->storage->resolveDatabaseFile($file['filepath'])));
                return ['request_id'=>42,'file_count'=>1];
            });
            $this->assertSame(1,$result['file_count']);
            try {
                $transfer->transmit(['ACADEMIC YEAR/2026/Event/Nested/photo.jpg'], ['ACADEMIC YEAR/2026/Event'], 42, $actor, static function () use ($db) { $db->transStart(); throw new RuntimeException('Assignment failed'); });
                $this->fail('Failed assignment must roll back.');
            } catch (RuntimeException $e) { $this->assertSame('Assignment failed',$e->getMessage()); }
            $this->assertSame(0,$db->transDepth);
            $this->assertSame(1,$db->table('files')->countAllResults());
            $this->assertSame(2,$db->table('folder')->countAllResults());
            $this->assertCount(1,glob($this->root.'/local/*/*/Event/Nested/*.jpg'));
            $this->assertSame($before,$this->snapshot());
            $db->table('media_requests')->where('id',42)->update(['status'=>'pending_incomplete']);
            $transfer->transmit(['ACADEMIC YEAR/2026/Event/Nested/photo.jpg'], ['ACADEMIC YEAR/2026/Event'], 42, $actor, static fn () => ['file_count'=>1]);
            $this->assertSame(2,$db->table('folder')->countAllResults(), 'Corrections reuse the selected folder tree.');
            $transfer->transmit(['ACADEMIC YEAR/2026/Event/Nested/photo.jpg'], [], 42, $actor, function ($ids, $folders) use ($db) {
                $this->assertSame([], $folders);
                $file = $db->table('files')->where('id',$ids[0])->get()->getRowArray();
                $this->assertNull($file['folder_id'], 'Individually selected nested files arrive at root.');
                return ['file_count'=>1];
            });
            $this->assertSame(2,$db->table('folder')->countAllResults());
            $db->query('CREATE TABLE user (role TEXT, department TEXT, is_active INTEGER)');
            $db->table('user')->insert(['role'=>'department','department'=>'CCS','is_active'=>1]);
            $result = $transfer->direct(['photo.jpg','ACADEMIC YEAR/2026/Event/Nested/photo.jpg'], ['ACADEMIC YEAR/2026/Event'], 'CCS', $actor, function ($ids,$folders,$department) use ($db) {
                $this->assertSame('CCS', $department);
                $this->assertCount(2,$ids);
                $this->assertCount(1,$folders);
                $folder = $db->table('folder')->where('id',$folders[0])->get()->getRowArray();
                $this->assertSame('Event',$folder['name']);
                $this->assertNull($folder['parent_id']);
                $this->assertNull($db->table('files')->where('id',$ids[0])->get()->getRowArray()['folder_id']);
                return ['request_id'=>43,'file_count'=>2];
            });
            $this->assertSame(43,$result['request_id']);
            $this->assertSame($before,$this->snapshot());
            try {
                $transfer->direct(['photo.jpg'], [], 'Unknown', $actor);
                $this->fail('Inactive departments must be rejected.');
            } catch (RuntimeException $error) { $this->assertSame('Choose an active department.', $error->getMessage()); }
            $db->table('media_requests')->where('id',42)->update(['status'=>'complete']);
            $this->expectException(RuntimeException::class);
            $transfer->transmit(['photo.jpg'],[],42,$actor);
        } finally { $db->close(); }
    }

    private function snapshot(): array
    {
        $snapshot=[];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root.'/share',FilesystemIterator::SKIP_DOTS)) as $entry) {
            if ($entry->isFile() && !$entry->isLink()) $snapshot[$entry->getPathname()] = hash_file('sha256',$entry->getPathname());
        }
        ksort($snapshot);
        return $snapshot;
    }

    public function testDestinationValidationDoesNotCreateAnythingOnTheShare(): void
    {
        $config = new class extends Storage {
            public string $testRoot;
            public function root(): string { return $this->testRoot.'/share/unsafe'; }
            public function departmentRoot(): string { return $this->testRoot.'/departments'; }
        };
        $config->testRoot = $this->root;
        $rejected = false;
        try { (new StorageService($config))->assertSeparateFromSource(realpath($this->root.'/share')); }
        catch (RuntimeException) { $rejected = true; }
        $this->assertTrue($rejected);
        $this->assertDirectoryDoesNotExist($this->root.'/share/unsafe');
    }
    public function testUnsupportedServerEntryDoesNotBreakListingCountsOrUploadScan(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') $this->markTestSkipped('Backslashes are literal filenames only on Unix shares.');
        mkdir($this->root.'/share/Bad\\folder');
        file_put_contents($this->root.'/share/Bad\\folder/hidden.txt', 'must not be traversed');
        file_put_contents($this->root.'/share/Bad\\file.txt', 'unsupported name');
        $listing = $this->library->browse('', '', 0, null, '', 1);
        $this->assertSame(['Album', 'photo.jpg'], array_column($listing['items'], 'path'));
        $counts = $this->library->folderCounts('');
        $this->assertSame(['files'=>1, 'folders'=>1], $counts);
        $walk = iterator_to_array($this->library->walkFiles());
        $this->assertCount(46, $walk);
        $this->assertArrayNotHasKey('Bad\\folder/hidden.txt', $walk);
        $search = $this->library->browse('', 'file1', 0, null, '', 1);
        $this->assertSame(11, $search['file_total']);
        // Existing confinement still rejects a direct request for an unsupported path.
        $this->expectException(RuntimeException::class);
        $this->library->resolve('Bad\\file.txt');
    }

}
