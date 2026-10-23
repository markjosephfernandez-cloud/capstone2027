<?php

use App\Services\ServerLibraryEditor;
use App\Services\ServerLibraryService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Storage;

final class ServerLibraryEditorTest extends CIUnitTestCase
{
    public function testTrashAuditCapturesReadablePathBeforeDeletion(): void
    {
        $this->editor->trash(['Album/photo.jpg'], 7);
        $items = $this->editor->trashItems();
        $id = $items[0]['id'];
        $label = $this->editor->trashAuditLabel($id);
        $this->assertSame('Album/photo.jpg', $label);
        $this->editor->permanentlyDelete($id);
        $this->assertSame([], $this->editor->trashItems());
        $this->assertSame('Album/photo.jpg', $label);
    }

    public function testLegacyTrashAuditDisplaysStoredNameSafely(): void
    {
        $description = 'Trash item external-' . bin2hex('<photo & copy>.jpg');
        $formatted = \App\Libraries\TrashAuditDescription::format('SERVER_TRASH_DELETE', $description);
        $this->assertSame('Permanently deleted from server Trash: <photo & copy>.jpg (stored Trash name; original path unknown)', $formatted);
        $this->assertStringContainsString('&lt;photo &amp; copy&gt;.jpg', esc($formatted));
        $this->assertSame($description, \App\Libraries\TrashAuditDescription::format('OTHER_ACTION', $description));
        foreach (['Trash item external-f', 'Trash item external-00', 'Trash item abc123'] as $invalid) {
            $this->assertSame($invalid, \App\Libraries\TrashAuditDescription::format('SERVER_TRASH_DELETE', $invalid));
        }
    }

    private string $root;
    private ServerLibraryService $library;
    private ServerLibraryEditor $editor;
    private Storage $config;
    private array $counts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/server-edit-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/share/Album', 0775, true);
        file_put_contents($this->root . '/share/Album/photo.jpg', 'original');
        $config = new class($this->root) extends Storage {
            public function __construct(private string $testRoot) { parent::__construct(); }
            public function scanSourceRoot(): string { return $this->testRoot . '/share'; }
            public function root(): string { return $this->testRoot . '/local'; }
            public function departmentRoot(): string { return $this->testRoot . '/departments'; }
            public function legacyRoots(): array { return []; }
        };
        $this->config = $config;
        $cache = $this->createMock(\CodeIgniter\Cache\CacheInterface::class);
        $cache->method('get')->willReturnCallback(fn ($key) => $this->counts[$key] ?? null);
        $cache->method('save')->willReturnCallback(function ($key, $value) { $this->counts[$key] = $value; return true; });
        $cache->method('delete')->willReturnCallback(function ($key) { unset($this->counts[$key]); return true; });
        $this->library = new ServerLibraryService($config, $cache);
        $this->editor = new ServerLibraryEditor($this->library, $config);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->root);
        parent::tearDown();
    }

    private function reject(callable $operation): void
    {
        try { $operation(); $this->fail('The unsafe operation was accepted.'); }
        catch (RuntimeException $exception) { $this->assertNotSame('', $exception->getMessage()); }
    }

    public function testTrashPreviewsResolveRenamedFilesAndFolderChildrenWithoutRestoring(): void
    {
        $this->editor->rename('Album/photo.jpg', 'renamed.jpg');
        $this->editor->trash(['Album'], 7);
        $id = array_key_first($this->editor->state()['trash']);
        $before = $this->editor->state();
        $path = $this->editor->previewPath($id, 'renamed.jpg');
        $this->assertSame('original', file_get_contents($path));
        $this->assertStringContainsString('/TRASH/', $path);
        $this->assertSame(['renamed.jpg'], array_column($this->editor->previewFolder($id)['items'], 'name'));
        foreach (['../escape', '/absolute', 'renamed.jpg/../escape', 'missing.jpg', '.hidden', 'bad\\path'] as $child) {
            $this->reject(fn () => $this->editor->previewPath($id, $child));
        }
        symlink($this->root . '/share', dirname($path) . '/linked');
        $this->reject(fn () => $this->editor->previewPath($id, 'linked/anything'));
        $this->reject(fn () => $this->editor->previewPath('../invalid'));
        $this->assertSame($before, $this->editor->state());
        $this->assertDirectoryDoesNotExist($this->root . '/share/Album');
    }

    public function testTrashPreviewResolvesExternalAndLegacyStorageButRejectsMissingFiles(): void
    {
        mkdir($this->root . '/share/TRASH');
        file_put_contents($this->root . '/share/TRASH/external.png', 'external');
        $external = 'external-' . bin2hex('external.png');
        $this->assertSame('external', file_get_contents($this->editor->previewPath($external)));
        $this->assertSame([], $this->editor->state()['trash']);
        $this->editor->trash(['Album/photo.jpg'], 7);
        $state = $this->editor->state();
        $id = array_key_first($state['trash']);
        $old = $this->editor->previewPath($id);
        $legacy = $this->root . '/share/.greyhound-library/trash-' . $id;
        rename($old, $legacy);
        unset($state['trash'][$id]['stored_name']);
        file_put_contents($this->root . '/share/.greyhound-library/metadata.json', json_encode($state));
        $this->assertSame(realpath($legacy), $this->editor->previewPath($id));
        unlink($legacy);
        $this->reject(fn () => $this->editor->previewPath($id));
    }

    public function testCreateRenameMoveAndTrashRestorePreserveContentsAndTags(): void
    {
        $this->assertSame(['files'=>0, 'folders'=>1], $this->library->folderCounts(''));
        $this->editor->createFolder('', 'Events');
        $this->assertSame(['files'=>0, 'folders'=>2], $this->library->folderCounts(''));
        $this->editor->addTags(['Album/photo.jpg'], [' Founder ', 'FOUNDER', 'University']);
        $this->assertCount(2, $this->editor->state()['tags']['Album/photo.jpg']);
        $this->assertSame(['photo.jpg'], array_column($this->library->browse('Album', 'founder')['items'], 'name'));
        $this->editor->rename('Album', 'Photos');
        $this->assertArrayHasKey('Photos/photo.jpg', $this->editor->state()['tags']);
        $this->editor->move(['Photos/photo.jpg'], 'Events');
        $this->assertSame('original', file_get_contents($this->root . '/share/Events/photo.jpg'));
        $this->assertSame(['files'=>0,'folders'=>0], $this->library->folderCounts('Photos'));
        $this->editor->trash(['Events'], 7);
        $this->assertDirectoryDoesNotExist($this->root . '/share/Events');
        $this->assertSame([], $this->editor->state()['tags']);
        $trash = $this->editor->state()['trash'];
        $this->assertSame(7, reset($trash)['deleted_by']);
        $this->assertSame(['Photos'], array_column($this->library->browse()['items'], 'name'));
        $this->editor->restore(array_key_first($trash));
        $this->assertSame('original', file_get_contents($this->root . '/share/Events/photo.jpg'));
        $this->assertArrayHasKey('Events/photo.jpg', $this->editor->state()['tags']);
        $this->assertSame([], $this->editor->state()['trash']);
    }

    public function testRejectsTraversalLinksRootEditsAndCollisionsWithoutChangingOriginals(): void
    {
        file_put_contents($this->root . '/secret', 'private');
        symlink($this->root, $this->root . '/share/link');
        foreach (['../escape', '/absolute', '.hidden', 'bad/name', 'bad\\name', 'bad\0name', 'bad:name'] as $name) {
            $this->reject(fn () => $this->editor->createFolder('', $name));
        }
        $this->reject(fn () => $this->editor->createFolder('link', 'escape'));
        $this->reject(fn () => $this->editor->rename('', 'renamed-root'));
        $this->reject(fn () => $this->editor->trash([''], 1));
        $this->reject(fn () => $this->editor->move(['Album'], 'Album'));
        $this->reject(fn () => $this->editor->trash(['Album', 'Album/photo.jpg'], 1));
        file_put_contents($this->root . '/share/taken.jpg', 'keep');
        $this->reject(fn () => $this->editor->rename('Album/photo.jpg', '../taken.jpg'));
        $this->reject(fn () => $this->editor->move(['taken.jpg'], ''));
        $this->assertSame('keep', file_get_contents($this->root . '/share/taken.jpg'));
        $this->assertSame('original', file_get_contents($this->root . '/share/Album/photo.jpg'));
        $this->assertSame('private', file_get_contents($this->root . '/secret'));
    }

    public function testFolderBatchMovesNestedContentsToDestinationAndBackToRoot(): void
    {
        $this->editor->createFolder('', 'Archive');
        $this->editor->createFolder('', 'Second');
        $this->editor->createFolder('Album', 'Nested');
        file_put_contents($this->root . '/share/Album/Nested/clip.mp4', 'video');
        $this->editor->addTags(['Album/photo.jpg'], ['event']);
        $this->editor->move(['Album', 'Second'], 'Archive');
        $this->assertFileExists($this->root . '/share/Archive/Album/Nested/clip.mp4');
        $this->assertArrayHasKey('Archive/Album/photo.jpg', $this->editor->state()['tags']);
        $this->editor->move(['Archive/Album', 'Archive/Second'], '');
        $this->assertSame('video', file_get_contents($this->root . '/share/Album/Nested/clip.mp4'));
        $this->editor->trash(['Album', 'Second'], 1);
        $this->assertDirectoryDoesNotExist($this->root . '/share/Album');
        $this->assertCount(2, $this->editor->state()['trash']);
        foreach (array_keys($this->editor->state()['trash']) as $id) $this->editor->restore($id);
        $this->assertFileExists($this->root . '/share/Album/Nested/clip.mp4');
    }

    public function testFolderBatchConflictAndCircularDestinationLeaveSourcesIntact(): void
    {
        $this->editor->createFolder('', 'Archive');
        $this->editor->createFolder('', 'Second');
        $this->editor->createFolder('Archive', 'Album');
        $this->editor->createFolder('Album', 'Nested');
        $this->reject(fn () => $this->editor->move(['Second', 'Album'], 'Archive'));
        $this->assertDirectoryExists($this->root . '/share/Second');
        $this->assertDirectoryDoesNotExist($this->root . '/share/Archive/Second');
        $this->reject(fn () => $this->editor->move(['Album'], 'Album/Nested'));
        $this->reject(fn () => $this->editor->move(['Album'], 'Album'));
        $this->assertSame('original', file_get_contents($this->root . '/share/Album/photo.jpg'));
    }

    public function testBatchConflictsAndRestoreConflictsDoNotOverwriteFiles(): void
    {
        mkdir($this->root . '/share/Destination');
        file_put_contents($this->root . '/share/other.jpg', 'other');
        file_put_contents($this->root . '/share/Destination/photo.jpg', 'keep');
        $this->reject(fn () => $this->editor->move(['other.jpg', 'Album/photo.jpg'], 'Destination'));
        $this->assertFileExists($this->root . '/share/other.jpg');
        $this->assertFileDoesNotExist($this->root . '/share/Destination/other.jpg');
        $this->editor->trash(['Album/photo.jpg'], 1);
        $id = array_key_first($this->editor->state()['trash']);
        file_put_contents($this->root . '/share/Album/photo.jpg', 'replacement');
        $this->reject(fn () => $this->editor->restore($id));
        $this->assertSame('replacement', file_get_contents($this->root . '/share/Album/photo.jpg'));
        $this->assertArrayHasKey($id, $this->editor->state()['trash']);
    }

    public function testCorruptOrLinkedMetadataBlocksEditsAndDisconnectedShareIsNotCreated(): void
    {
        $this->editor->createFolder('', 'New');
        file_put_contents($this->root . '/share/.greyhound-library/metadata.json', '{broken');
        $this->reject(fn () => $this->editor->rename('Album/photo.jpg', 'new.jpg'));
        $this->assertFileExists($this->root . '/share/Album/photo.jpg');
        rename($this->root . '/share', $this->root . '/offline');
        $this->reject(fn () => $this->editor->createFolder('', 'No'));
        $this->assertDirectoryDoesNotExist($this->root . '/share');
        rename($this->root . '/offline', $this->root . '/share');
        unlink($this->root . '/share/.greyhound-library/metadata.json');
        file_put_contents($this->root . '/secret', '{}');
        symlink($this->root . '/secret', $this->root . '/share/.greyhound-library/metadata.json');
        $this->reject(fn () => $this->editor->createFolder('', 'No'));
        $this->assertSame('{}', file_get_contents($this->root . '/secret'));
    }

    public function testUploadWritesDirectlyToShareAndRejectsInvalidBatches(): void
    {
        $image = imagecreatetruecolor(8, 4);
        imagejpeg($image, $this->root . '/upload.jpg'); imagedestroy($image);
        $upload = $this->getMockBuilder(UploadedFile::class)->setConstructorArgs([$this->root . '/upload.jpg', 'new.jpg', 'image/jpeg', filesize($this->root . '/upload.jpg'), UPLOAD_ERR_OK])->onlyMethods(['isValid'])->getMock();
        $upload->method('isValid')->willReturn(true);
        $this->editor->upload('Album', [$upload]);
        $this->assertSame(hash_file('sha256', $this->root . '/upload.jpg'), hash_file('sha256', $this->root . '/share/Album/new.jpg'));
        $this->reject(fn () => $this->editor->upload('Album', [$upload]));
        $bad = $this->getMockBuilder(UploadedFile::class)->setConstructorArgs([$this->root . '/upload.jpg', 'bad.php', 'image/jpeg', filesize($this->root . '/upload.jpg'), UPLOAD_ERR_OK])->onlyMethods(['isValid'])->getMock();
        $bad->method('isValid')->willReturn(true);
        $this->reject(fn () => $this->editor->upload('', [$upload, $bad]));
        $this->assertFileDoesNotExist($this->root . '/share/new.jpg');
        $this->assertFileDoesNotExist($this->root . '/share/bad.php');
    }

    public function testServerTrashStoresFilesInReservedFolderAndRestoresOriginalPath(): void
    {
        $this->editor->trash(['Album/photo.jpg'], 7);
        $state = $this->editor->state();
        $id = array_key_first($state['trash']);
        $stored = $this->root . '/share/TRASH/' . $state['trash'][$id]['stored_name'];
        $this->assertSame('original', file_get_contents($stored));
        $this->assertCount(1, $this->editor->trashItems());
        $this->reject(fn () => $this->library->resolve('TRASH', true));
        $this->reject(fn () => $this->editor->createFolder('', 'TRASH'));
        $this->assertNotContains('TRASH', array_column($this->library->browse()['items'], 'name'));
        $this->editor->restore($id);
        $this->assertSame('original', file_get_contents($this->root . '/share/Album/photo.jpg'));
        $this->assertFileDoesNotExist($stored);
    }

    public function testManualTrashEntriesNeedDestinationAndCannotOverwriteFiles(): void
    {
        mkdir($this->root . '/share/TRASH');
        file_put_contents($this->root . '/share/TRASH/photo.jpg', 'manual');
        $item = $this->editor->trashItems()[0];
        $this->assertTrue($item['original_unknown']);
        $this->reject(fn () => $this->editor->restore($item['id']));
        $this->reject(fn () => $this->editor->restore($item['id'], 'Album'));
        $this->reject(fn () => $this->editor->restore($item['id'], '../outside'));
        $this->editor->restore($item['id'], '');
        $this->assertSame('manual', file_get_contents($this->root . '/share/photo.jpg'));
        file_put_contents($this->root . '/share/TRASH/old.txt', 'old');
        $this->editor->permanentlyDelete('external-' . bin2hex('old.txt'));
        $this->assertFileDoesNotExist($this->root . '/share/TRASH/old.txt');
    }

    public function testRetentionPurgesExpiredFoldersButKeepsRecentAndUndatedItems(): void
    {
        \Config\Services::injectMock('audit', $this->createMock(\App\Services\AuditService::class));
        $this->editor->trash(['Album'], 7);
        $state = $this->editor->state();
        $id = array_key_first($state['trash']);
        $stored = $this->root . '/share/TRASH/' . $state['trash'][$id]['stored_name'];
        $state['trash'][$id]['deleted_at'] = gmdate('Y-m-d H:i:s', time() - 31 * 86400);
        file_put_contents($this->root . '/share/.greyhound-library/metadata.json', json_encode($state));
        file_put_contents($this->root . '/share/recent.txt', 'keep');
        $this->editor->trash(['recent.txt'], 7);
        file_put_contents($this->root . '/share/TRASH/undated.txt', 'keep unknown');
        touch($this->root . '/share/TRASH/undated.txt', time() - 90 * 86400);
        $this->assertSame(1, $this->editor->purgeExpiredTrash());
        $this->assertDirectoryDoesNotExist($stored);
        $this->assertFileExists($this->root . '/share/TRASH/undated.txt');
        $items = array_column($this->editor->trashItems(), null, 'name');
        $this->assertSame(30, $items['recent.txt']['trash_days_remaining']);
        $this->assertSame(30, $items['undated.txt']['trash_days_remaining']);
        $started = $items['undated.txt']['retention_started_at'];
        $this->assertSame($started, $this->editor->state()['trash'][$items['undated.txt']['id']]['retention_started_at']);
        $this->assertSame(0, $this->editor->purgeExpiredTrash());
        $state = $this->editor->state();
        $state['trash'][$items['undated.txt']['id']]['retention_started_at'] = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        file_put_contents($this->root . '/share/.greyhound-library/metadata.json', json_encode($state));
        $this->assertSame(1, $this->editor->purgeExpiredTrash());
        $this->assertFileDoesNotExist($this->root . '/share/TRASH/undated.txt');
        $this->assertCount(1, $this->editor->trashItems());
    }

    public function testLegacyPrivateTrashRemainsRestorable(): void
    {
        $this->editor->trash(['Album/photo.jpg'], 7);
        $state = $this->editor->state();
        $id = array_key_first($state['trash']);
        rename($this->root . '/share/TRASH/' . $state['trash'][$id]['stored_name'], $this->root . '/share/.greyhound-library/trash-' . $id);
        unset($state['trash'][$id]['stored_name']);
        file_put_contents($this->root . '/share/.greyhound-library/metadata.json', json_encode($state));
        $this->assertCount(1, $this->editor->trashItems());
        $this->editor->restore($id);
        $this->assertFileExists($this->root . '/share/Album/photo.jpg');
    }

    public function testPermanentDeletionDoesNotFollowLinksOrDeleteReplacementFiles(): void
    {
        file_put_contents($this->root . '/outside.jpg', 'keep outside');
        symlink($this->root . '/outside.jpg', $this->root . '/share/Album/link.jpg');
        $this->editor->trash(['Album'], 7);
        $id = array_key_first($this->editor->state()['trash']);
        mkdir($this->root . '/share/Album');
        file_put_contents($this->root . '/share/Album/photo.jpg', 'replacement');
        $this->editor->permanentlyDelete($id);
        $this->assertSame([], $this->editor->state()['trash']);
        $this->assertFileDoesNotExist($this->root . '/share/.greyhound-library/trash-' . $id);
        $this->assertSame('keep outside', file_get_contents($this->root . '/outside.jpg'));
        $this->assertSame('replacement', file_get_contents($this->root . '/share/Album/photo.jpg'));
        $this->reject(fn () => $this->editor->permanentlyDelete('../outside.jpg'));
    }

    public function testInterruptedPermanentDeletionCanBeRetriedButNotRestored(): void
    {
        $this->editor->trash(['Album'], 7);
        $id = array_key_first($this->editor->state()['trash']);
        $interrupted = new class($this->library) extends ServerLibraryEditor {
            private int $writes = 0;
            protected function saveState(array $state, string $directory): void {
                if (++$this->writes === 2) throw new RuntimeException('Metadata connection interrupted');
                parent::saveState($state, $directory);
            }
        };
        $this->reject(fn () => $interrupted->permanentlyDelete($id));
        $this->assertTrue($this->editor->state()['trash'][$id]['deleting']);
        $this->reject(fn () => $this->editor->restore($id));
        $this->editor->permanentlyDelete($id);
        $this->assertSame([], $this->editor->state()['trash']);
    }

    public function testFailedMetadataCommitRollsBackTheFilesystemEdit(): void
    {
        $this->editor->createFolder('', 'New');
        $path = $this->root . '/share/.greyhound-library/metadata.json';
        $before = file_get_contents($path);
        // Simulate an unavailable metadata writer without relying on OS permission bits.
        $blocked = new class($this->library) extends ServerLibraryEditor {
            protected function saveState(array $state, string $directory): void { throw new RuntimeException('Metadata unavailable'); }
        };
        $this->reject(fn () => $blocked->rename('Album/photo.jpg', 'different.jpg'));
        $this->assertFileExists($this->root . '/share/Album/photo.jpg');
        $this->assertSame($before, file_get_contents($path));
        $lock = fopen($this->root . '/share/.greyhound-library/edit.lock', 'c');
        flock($lock, LOCK_EX);
        try { $this->reject(fn () => $this->editor->createFolder('', 'Busy')); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
        $this->assertDirectoryDoesNotExist($this->root . '/share/Busy');
    }

    public function testPostCreatesAReviewCopyWithTagsAndLeavesServerOriginalUntouched(): void
    {
        $db = db_connect();
        $tables = ['files', 'folder', 'mobile_posts'];
        foreach ($tables as $table) $db->query('DROP TABLE IF EXISTS ' . $db->prefixTable($table));
        $db->query('CREATE TABLE '.$db->prefixTable('folder').' (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, path TEXT, parent_id INTEGER, is_deleted INTEGER, created_at TEXT)');
        $db->query('CREATE TABLE '.$db->prefixTable('files').' (id INTEGER PRIMARY KEY AUTOINCREMENT, filename TEXT, display_title TEXT, filepath TEXT, filetype TEXT, mime_type TEXT, media_kind TEXT, tags TEXT, size INTEGER, folder_id INTEGER, employee_id INTEGER, dateupload TEXT, is_deleted INTEGER, description TEXT, last_action TEXT, last_action_at TEXT, app_post_id INTEGER, app_published_at TEXT, app_pinned INTEGER, release_status TEXT, published_at TEXT, publication_channel TEXT, published_by INTEGER)');
        $db->query('CREATE TABLE '.$db->prefixTable('mobile_posts').' (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT, description TEXT, channel TEXT, thumbnail_mode TEXT, thumbnail_ids TEXT, created_at TEXT, updated_at TEXT)');
        $db->resetDataCache();
        try {
            $image = imagecreatetruecolor(8, 4);
            imagejpeg($image, $this->root . '/share/Album/photo.jpg'); imagedestroy($image);
            $hash = hash_file('sha256', $this->root . '/share/Album/photo.jpg');
            $this->editor->addTags(['Album/photo.jpg'], ['Founder']);
            $storage = new \App\Services\StorageService($this->config);
            $service = new \App\Services\ServerLibraryTransferService($db, $this->library, $storage);
            $ids = $service->post(['Album/photo.jpg'], 'Campus event', 'From the server', ['role'=>'admin','employee_id'=>7]);
            $this->assertCount(1, $ids);
            $file = $db->table('files')->get()->getRowArray();
            $this->assertSame('Founder', $file['tags']);
            $this->assertSame('image', $file['media_kind']);
            $this->assertSame(7, (int)$file['published_by']);
            $this->assertSame('published', $file['release_status']);
            $this->assertNull($file['app_published_at']);
            $this->assertSame($hash, hash_file('sha256', $storage->resolveDatabaseFile($file['filepath'])));
            $this->assertSame($hash, hash_file('sha256', $this->root . '/share/Album/photo.jpg'));
            $this->assertSame('Campus event', $db->table('mobile_posts')->get()->getRowArray()['title']);
            $this->reject(fn () => $service->post(['Album/photo.jpg'], 'Blocked', '', ['role'=>'writer','employee_id'=>8]));
            $this->assertSame(1, $db->table('files')->countAllResults());

            // The confirmation form sends IDs only, including camera filenames
            // whose underscores are not valid user-entered display titles.
            $selection = [];
            for ($index = 773; $index < 793; $index++) {
                $path = 'Album/IMG_0' . $index . '.jpg';
                copy($this->root . '/share/Album/photo.jpg', $this->root . '/share/' . $path);
                $selection[] = bin2hex($path);
            }
            $session = $this->createMock(\CodeIgniter\Session\Session::class);
            $actor = ['isLoggedIn'=>true, 'role'=>'admin', 'employee_id'=>7];
            $session->method('get')->willReturnCallback(static fn ($key = null) => $key === null ? $actor : ($actor[$key] ?? null));
            $session->expects($this->once())->method('close');
            $session->expects($this->once())->method('start');
            \Config\Services::injectMock('session', $session);
            \Config\Services::injectMock('storage', $storage);
            $controller = new class($this->library) extends \App\Controllers\Web\ServerLibraryActionsController {
                public function __construct(private ServerLibraryService $testLibrary) {}
                protected function library(): ServerLibraryService { return $this->testLibrary; }
                protected function recordEdit(string $action, array $paths): void {}
            };
            $request = new \CodeIgniter\HTTP\IncomingRequest(config('App'), new \CodeIgniter\HTTP\URI('http://example.com/'), null, new \CodeIgniter\HTTP\UserAgent());
            $request->setGlobal('get', []);
            $request->setGlobal('post', ['post_type'=>'files', 'file_ids'=>$selection]);
            $controller->initController($request, new \CodeIgniter\HTTP\Response(config('App')), service('logger'));
            $response = $controller->action('post');
            $this->assertSame(site_url('workspace/published-files'), $response->getHeaderLine('Location'));
            $this->assertSame(2, $db->table('mobile_posts')->countAllResults());
            $this->assertSame(21, $db->table('files')->countAllResults());
            $this->assertSame('', $db->table('mobile_posts')->orderBy('id', 'DESC')->get()->getRowArray()['title']);
            $posted = $db->table('files')->orderBy('id', 'DESC')->get()->getRowArray();
            $this->assertNull($posted['app_published_at']);
            $this->assertSame($hash, hash_file('sha256', $storage->resolveDatabaseFile($posted['filepath'])));
            $this->assertSame($hash, hash_file('sha256', $this->root . '/share/Album/IMG_0773.jpg'));
        } finally {
            \Config\Services::resetSingle('session');
            \Config\Services::resetSingle('storage');
            foreach ($tables as $table) $db->query('DROP TABLE IF EXISTS ' . $db->prefixTable($table));
            $db->resetDataCache();
        }
    }

    public function testParallelCopiesReserveDuplicateNamesAndCleanUpRejectedBatches(): void
    {
        $image = imagecreatetruecolor(8, 4);
        imagejpeg($image, $this->root . '/share/Album/photo.jpg');
        imagedestroy($image);
        mkdir($this->root . '/share/Other');
        copy($this->root . '/share/Album/photo.jpg', $this->root . '/share/Other/photo.jpg');
        $storage = new \App\Services\StorageService($this->config);
        $sources = [$this->root . '/share/Album/photo.jpg', $this->root . '/share/Other/photo.jpg'];
        $copies = $storage->storeServerCopies($sources, 'parallel');
        $this->assertSame(['photo.jpg', 'photo (1).jpg'], array_column($copies, 'filename'));
        foreach ($copies as $index => $copy) {
            $this->assertSame(hash_file('sha256', $sources[$index]), hash_file('sha256', $copy['filepath']));
        }
        file_put_contents($this->root . '/share/bad.jpg', '<html>not an image</html>');
        $this->reject(fn () => $storage->storeServerCopies([$sources[0], $this->root . '/share/bad.jpg'], 'failed'));
        $this->assertSame([], glob($this->root . '/local/failed/*'));
        $this->assertFileExists($sources[0]);
    }

    public function testCopyWorkersRunConcurrentlyAndStopAtTheirDeadline(): void
    {
        if (!function_exists('posix_mkfifo') || !function_exists('proc_open')) $this->markTestSkipped('Requires POSIX pipes and process support.');
        $fifo = $this->root . '/blocked-source';
        posix_mkfifo($fifo, 0600);
        $copier = new \App\Libraries\ParallelFileCopier();
        $started = microtime(true);
        try {
            $copier->copy([
                [$fifo, $this->root . '/blocked-copy'],
                [$this->root . '/share/Album/photo.jpg', $this->root . '/fast-copy'],
            ], 2, 1);
            $this->fail('The blocked copy must time out.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('timed out', $error->getMessage());
            $this->assertLessThan(4, microtime(true) - $started);
            $this->assertSame('original', file_get_contents($this->root . '/fast-copy'), 'The second worker must finish while the first is blocked.');
        }
    }

    public function testCopyWorkersAreLimitedToTwoAndSequentialModeWorks(): void
    {
        if (!function_exists('posix_mkfifo') || !function_exists('proc_open')) $this->markTestSkipped('Requires POSIX pipes and process support.');
        $fifo = $this->root . '/blocked-source';
        posix_mkfifo($fifo, 0600);
        $copier = new \App\Libraries\ParallelFileCopier();
        $this->reject(fn () => $copier->copy([
            [$fifo, $this->root . '/blocked-one'], [$fifo, $this->root . '/blocked-two'],
            [$this->root . '/share/Album/photo.jpg', $this->root . '/third-copy'],
        ], 10, 1));
        $this->assertFileDoesNotExist($this->root . '/third-copy');
        $copier->copy([[$this->root . '/share/Album/photo.jpg', $this->root . '/serial-copy']], 1);
        $this->assertSame('original', file_get_contents($this->root . '/serial-copy'));
    }
}
