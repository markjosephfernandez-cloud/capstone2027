<?php

use App\Services\StorageService;
use App\Services\TrashService;
use App\Models\FolderModel;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Config\Storage;

final class PhysicalTrashTest extends CIUnitTestCase
{
    private string $temporaryRoot;
    private TrashService $trash;
    private StorageService $storage;
    private array $actor = ['employee_id' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryRoot = sys_get_temp_dir() . '/greyhound-trash-test-' . bin2hex(random_bytes(6));
        $config = new class extends Storage {
            public string $temporaryRoot;
            public function root(): string { return $this->temporaryRoot . '/uploads'; }
            public function departmentRoot(): string { return $this->temporaryRoot . '/Department'; }
            public function legacyRoots(): array { return []; }
        };
        $config->temporaryRoot = $this->temporaryRoot;
        $this->storage = new StorageService($config);
        Services::injectMock('storage', $this->storage);
        $this->db = db_connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $this->db->query('CREATE TABLE files (id INTEGER PRIMARY KEY, filename TEXT, filepath TEXT, folder_id INTEGER NULL, is_deleted INTEGER DEFAULT 0, last_action TEXT, last_action_at TEXT)');
        $this->db->query("CREATE TABLE trashfiles (id INTEGER PRIMARY KEY, file_id INTEGER UNIQUE, folder_root_id INTEGER NULL, original_filepath TEXT NULL, deleted_at TEXT, deleted_by INTEGER, reason TEXT CHECK(reason != 'fail'))");
        $this->db->query('CREATE TABLE folder (id INTEGER PRIMARY KEY, name TEXT, path TEXT, parent_id INTEGER NULL, is_deleted INTEGER DEFAULT 0, deleted_at TEXT, deleted_by INTEGER, deletion_reason TEXT, trash_root_id INTEGER NULL)');
        $this->trash = new TrashService($this->db);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        if (is_dir($this->temporaryRoot)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->temporaryRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->temporaryRoot);
        }
        parent::tearDown();
    }

    private function media(int $id, string $path, ?int $folder = null): array
    {
        $physical = $this->storage->root() . '/' . $path;
        if (! is_dir(dirname($physical))) mkdir(dirname($physical), 0775, true);
        file_put_contents($physical, 'media content ' . $id);
        $file = ['id' => $id, 'filename' => basename($path), 'filepath' => $this->storage->databasePath($physical), 'folder_id' => $folder, 'is_deleted' => 0];
        $this->db->table('files')->insert($file);
        return $file;
    }

    private function file(int $id): array
    {
        return $this->db->table('files')->where('id', $id)->get()->getRowArray();
    }

    public function testImagesAndVideosMoveAndRestoreToExactOriginalLocations(): void
    {
        foreach (['Events/Nested/photo.jpg', 'Videos/clip.mp4'] as $index => $path) {
            $id = $index + 1;
            $file = $this->media($id, $path);
            $original = $this->storage->resolveDatabaseFile($file['filepath']);
            $hash = hash_file('sha256', $original);
            $this->trash->moveToTrash([$file], $this->actor, 'test');
            $trashed = $this->file($id);
            $this->assertSame('writable/uploads/Trashed/' . basename($path), $trashed['filepath']);
            $this->assertFileDoesNotExist($original);
            $trashPath = $this->storage->resolveDatabaseFile($trashed['filepath']);
            $this->assertSame($hash, hash_file('sha256', $trashPath));
            rmdir(dirname($original));
            $this->trash->restoreMedia($trashed);
            $this->assertSame($file['filepath'], $this->file($id)['filepath']);
            $this->assertSame($hash, hash_file('sha256', $original));
            $this->assertFileDoesNotExist($trashPath);
            $this->assertSame(0, $this->db->table('trashfiles')->countAllResults());
        }
    }

    public function testDuplicateNamesRemainSeparateAndPermanentDeletePreservesReplacement(): void
    {
        $first = $this->media(1, 'One/photo.jpg');
        $second = $this->media(2, 'Two/photo.jpg');
        $this->trash->moveToTrash([$first, $second], $this->actor, 'test');
        $firstTrash = $this->storage->resolveDatabaseFile($this->file(1)['filepath']);
        $secondTrash = $this->storage->resolveDatabaseFile($this->file(2)['filepath']);
        $replacement = $this->storage->root() . '/One/photo.jpg';
        file_put_contents($replacement, 'replacement');
        $this->trash->permanentlyRemoveMedia($this->file(1), $this->actor, 'TEST');
        $this->assertFileDoesNotExist($firstTrash);
        $this->assertFileExists($secondTrash);
        $this->assertSame('replacement', file_get_contents($replacement));
        $this->assertSame(0, $this->db->table('files')->where('id', 1)->countAllResults());
        $this->assertSame(0, $this->db->table('trashfiles')->where('file_id', 1)->countAllResults());
    }

    public function testRestoreConflictLeavesFileInTrash(): void
    {
        $file = $this->media(1, 'photo.jpg');
        $this->trash->moveToTrash([$file], $this->actor, 'test');
        file_put_contents($this->storage->root() . '/photo.jpg', 'replacement');
        try {
            $this->trash->restoreMedia($this->file(1));
            $this->fail('Restore should reject a collision.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already exists', $exception->getMessage());
        }
        $this->assertSame(1, (int) $this->file(1)['is_deleted']);
        $this->assertFileExists($this->storage->resolveDatabaseFile($this->file(1)['filepath']));
        $this->assertSame('replacement', file_get_contents($this->storage->root() . '/photo.jpg'));
    }

    public function testDatabaseFailureRollsBackPhysicalMove(): void
    {
        $file = $this->media(1, 'photo.jpg');
        try {
            $this->trash->moveToTrash([$file], $this->actor, 'fail');
            $this->fail('The database constraint should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(0, (int) $this->file(1)['is_deleted']);
            $this->assertFileExists($this->storage->resolveDatabaseFile($file['filepath']));
            $this->assertFileDoesNotExist($this->storage->root() . '/Trashed/photo.jpg');
        }
    }

    public function testMissingFileRollsBackEarlierMovesInFolderBatch(): void
    {
        $first = $this->media(1, 'Events/photo.jpg');
        $second = $this->media(2, 'Events/clip.mp4');
        unlink($this->storage->resolveDatabaseFile($second['filepath']));
        try {
            $this->trash->moveToTrash([$first, $second], $this->actor, 'test');
            $this->fail('Missing media should fail.');
        } catch (RuntimeException $exception) {
            $this->assertSame(0, (int) $this->file(1)['is_deleted']);
            $this->assertFileExists($this->storage->resolveDatabaseFile($first['filepath']));
            $this->assertSame(0, $this->db->table('trashfiles')->countAllResults());
        }
    }

    public function testFolderTreeMovesAndRestoresAllMedia(): void
    {
        $this->db->table('folder')->insert(['id' => 1, 'name' => 'Events', 'path' => 'Events']);
        $this->db->table('folder')->insert(['id' => 2, 'name' => 'Nested', 'path' => 'Events/Nested', 'parent_id' => 1]);
        $first = $this->media(1, 'Events/photo.jpg', 1);
        $second = $this->media(2, 'Events/Nested/clip.mp4', 2);
        $this->trash->moveToTrash([$first, $second], $this->actor, 'folder', 1, [1, 2]);
        $this->assertFileDoesNotExist($this->storage->root() . '/Events/photo.jpg');
        $this->assertSame(2, $this->trash->restoreFolderTree(['id' => 1], $this->actor));
        $this->assertFileExists($this->storage->resolveDatabaseFile($first['filepath']));
        $this->assertFileExists($this->storage->resolveDatabaseFile($second['filepath']));
        $this->assertSame(0, $this->db->table('folder')->where('is_deleted', 1)->countAllResults());
    }

    public function testLegacyBackfillPreservesDeletionDateAndRestoresOriginal(): void
    {
        $file = $this->media(1, 'Old/photo.jpg');
        $this->db->table('files')->where('id', 1)->update(['is_deleted' => 1]);
        $this->db->table('trashfiles')->insert(['file_id' => 1, 'deleted_at' => '2026-09-01 10:00:00']);
        $this->trash->relocateLegacyTrash($this->file(1));
        $metadata = $this->db->table('trashfiles')->where('file_id', 1)->get()->getRowArray();
        $this->assertSame('2026-09-01 10:00:00', $metadata['deleted_at']);
        $this->assertSame($file['filepath'], $metadata['original_filepath']);
        $this->assertFileDoesNotExist($this->storage->root() . '/Old/photo.jpg');
        $this->trash->restoreMedia($this->file(1));
        $this->assertFileExists($this->storage->resolveDatabaseFile($file['filepath']));
    }

    public function testPermanentFolderDeletionRemovesMediaFromPhysicalTrash(): void
    {
        $this->db->table('folder')->insert(['id' => 1, 'name' => 'Events', 'path' => 'Events']);
        $file = $this->media(1, 'Events/clip.mp4', 1);
        $this->trash->moveToTrash([$file], $this->actor, 'folder', 1, [1]);
        $physical = $this->storage->resolveDatabaseFile($this->file(1)['filepath']);
        $this->assertSame(1, $this->trash->permanentlyRemoveFolder(['id' => 1, 'name' => 'Events'], $this->actor));
        $this->assertFileDoesNotExist($physical);
        $this->assertSame(0, $this->db->table('files')->countAllResults());
        $this->assertSame(0, $this->db->table('folder')->countAllResults());
    }

    public function testFolderNameConflictRestoresMediaIntoRenamedFolder(): void
    {
        $this->db->table('folder')->insert(['id' => 1, 'name' => 'Events', 'path' => 'Events']);
        $file = $this->media(1, 'Events/photo.jpg', 1);
        $this->trash->moveToTrash([$file], $this->actor, 'folder', 1, [1]);
        $this->db->table('folder')->insert(['id' => 2, 'name' => 'Events', 'path' => 'Events']);
        file_put_contents($this->storage->root() . '/Events/photo.jpg', 'replacement');
        $this->trash->restoreFolderTree(['id' => 1], $this->actor);
        $this->assertSame('writable/uploads/Events (restored)/photo.jpg', $this->file(1)['filepath']);
        $this->assertFileExists($this->storage->resolveDatabaseFile($this->file(1)['filepath']));
        $this->assertSame('replacement', file_get_contents($this->storage->root() . '/Events/photo.jpg'));
    }

    public function testRelocationRejectsTraversalAndSymlinkDestinations(): void
    {
        $file = $this->media(1, 'photo.jpg');
        symlink($this->temporaryRoot, $this->storage->root() . '/escape');
        foreach (['writable/uploads/../outside.jpg', 'writable/uploads/escape/outside.jpg'] as $destination) {
            try {
                $this->storage->relocateDatabaseFile($file['filepath'], $destination);
                $this->fail('An unsafe destination should be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertFileExists($this->storage->resolveDatabaseFile($file['filepath']));
                $this->assertFileDoesNotExist($this->temporaryRoot . '/outside.jpg');
            }
        }
    }

    public function testExistingNumberedFoldersFlattenAndKeepOriginalRestoreNames(): void
    {
        foreach ([1 => 'One', 2 => 'Two'] as $id => $folder) {
            $file = $this->media($id, 'Trashed/' . $id . '/photo.jpg');
            $this->db->table('files')->where('id', $id)->update(['is_deleted' => 1]);
            $this->db->table('trashfiles')->insert([
                'file_id' => $id, 'original_filepath' => 'writable/uploads/' . $folder . '/photo.jpg',
                'deleted_at' => '2026-09-01 10:00:00',
            ]);
            $this->trash->relocateLegacyTrash($this->file($id));
            $this->assertSame('writable/uploads/Trashed', dirname($this->file($id)['filepath']));
            $this->assertDirectoryDoesNotExist($this->storage->root() . '/Trashed/' . $id);
            $this->assertSame('media content ' . $id, file_get_contents($this->storage->resolveDatabaseFile($this->file($id)['filepath'])));
            $metadata = $this->db->table('trashfiles')->where('file_id', $id)->get()->getRowArray();
            $this->assertSame('2026-09-01 10:00:00', $metadata['deleted_at']);
            $this->assertSame('writable/uploads/' . $folder . '/photo.jpg', $metadata['original_filepath']);
            $before = $this->file($id)['filepath'];
            $this->trash->relocateLegacyTrash($this->file($id));
            $this->assertSame($before, $this->file($id)['filepath']);
        }
        $this->assertNotSame($this->file(1)['filepath'], $this->file(2)['filepath']);
        $this->trash->restoreMedia($this->file(2));
        $this->assertSame('writable/uploads/Two/photo.jpg', $this->file(2)['filepath']);
        $this->assertFileExists($this->storage->resolveDatabaseFile($this->file(1)['filepath']));
        $trashPath = $this->storage->resolveDatabaseFile($this->file(1)['filepath']);
        $this->trash->permanentlyRemoveMedia($this->file(1), $this->actor, 'TEST');
        $this->assertFileDoesNotExist($trashPath);
    }

    public function testRenamedFolderRestoresOriginalFilenameAfterTrashCollision(): void
    {
        $other = $this->media(1, 'Other/photo.jpg');
        $this->trash->moveToTrash([$other], $this->actor, 'test');
        $this->db->table('folder')->insert(['id' => 1, 'name' => 'Events', 'path' => 'Events']);
        $file = $this->media(2, 'Events/photo.jpg', 1);
        $this->trash->moveToTrash([$file], $this->actor, 'folder', 1, [1]);
        $this->assertNotSame('photo.jpg', basename($this->file(2)['filepath']));
        $this->db->table('folder')->insert(['id' => 2, 'name' => 'Events', 'path' => 'Events']);
        $this->trash->restoreFolderTree(['id' => 1], $this->actor);
        $this->assertSame('writable/uploads/Events (restored)/photo.jpg', $this->file(2)['filepath']);
        $this->assertFileExists($this->storage->resolveDatabaseFile($this->file(2)['filepath']));
    }

    public function testLegacyTrashCanStillRestore(): void
    {
        $file = $this->media(1, 'old.jpg');
        $this->db->table('files')->where('id', 1)->update(['is_deleted' => 1]);
        $this->db->table('trashfiles')->insert(['file_id' => 1]);
        $this->trash->restoreMedia($this->file(1));
        $this->assertSame(0, (int) $this->file(1)['is_deleted']);
        $this->assertFileExists($this->storage->resolveDatabaseFile($file['filepath']));
    }

    public function testRecreatedFolderSurvivesPermanentDeletionOfOriginalTree(): void
    {
        $this->db->table('folder')->insert(['id' => 1, 'name' => 'Events', 'path' => 'Events']);
        $this->db->table('folder')->insert(['id' => 2, 'name' => 'Nested', 'path' => 'Events/Nested', 'parent_id' => 1]);
        $file = $this->media(1, 'Events/Nested/photo.jpg', 2);
        $this->trash->moveToTrash([$file], $this->actor, 'folder', 1, [1, 2]);

        $path = (new FolderModel($this->db))->availableStoragePath('Events');
        $this->assertNotSame('Events', $path);
        $this->storage->directory($path, true);
        $this->db->table('folder')->insert(['id' => 3, 'name' => 'Events', 'path' => $path]);
        $replacement = $this->media(2, $path . '/photo.jpg', 3);

        $this->trash->permanentlyRemoveFolder(['id' => 1, 'name' => 'Events'], $this->actor);
        $this->assertSame('media content 2', file_get_contents($this->storage->resolveDatabaseFile($replacement['filepath'])));
        $this->assertSame('Events', $this->db->table('folder')->where('id', 3)->get()->getRowArray()['name']);
        $this->assertDirectoryDoesNotExist($this->storage->root() . '/Events');
    }

    public function testRecreatedNestedFolderAndRestoredOriginalKeepSeparateMedia(): void
    {
        $this->db->table('folder')->insert(['id' => 1, 'name' => 'Events', 'path' => 'Events']);
        $this->db->table('folder')->insert(['id' => 2, 'name' => 'Photos', 'path' => 'Events/Photos', 'parent_id' => 1]);
        $file = $this->media(1, 'Events/Photos/photo.jpg', 2);
        $this->trash->moveToTrash([$file], $this->actor, 'folder', 2, [2]);
        $path = (new FolderModel($this->db))->availableStoragePath('Events/Photos');
        $this->storage->directory($path, true);
        $this->db->table('folder')->insert(['id' => 3, 'name' => 'Photos', 'path' => $path, 'parent_id' => 1]);
        $replacement = $this->media(2, $path . '/photo.jpg', 3);

        $this->trash->restoreFolderTree(['id' => 2], $this->actor);
        $this->assertSame('writable/uploads/Events/Photos (restored)/photo.jpg', $this->file(1)['filepath']);
        $this->assertSame('media content 1', file_get_contents($this->storage->resolveDatabaseFile($this->file(1)['filepath'])));
        $this->assertSame('media content 2', file_get_contents($this->storage->resolveDatabaseFile($replacement['filepath'])));
    }

    public function testDeletedFolderPathsStayReservedEvenWithoutPhysicalDirectories(): void
    {
        $this->db->table('folder')->insert(['id' => 1, 'name' => 'Events', 'path' => 'Events', 'is_deleted' => 1]);
        $this->db->table('folder')->insert(['id' => 2, 'name' => 'Events', 'path' => 'Events (2)', 'is_deleted' => 1]);
        $this->storage->directory('Events (3)', true);
        $this->assertSame('Events (4)', (new FolderModel($this->db))->availableStoragePath('Events'));
        $this->assertSame('New Folder', (new FolderModel($this->db))->availableStoragePath('New Folder'));
    }

    public function testActiveFolderStillBlocksDuplicateEvenWhenItsDirectoryIsMissing(): void
    {
        $this->db->table('folder')->insert(['id' => 1, 'name' => 'Events', 'path' => 'Events']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A folder with that name already exists.');
        (new FolderModel($this->db))->availableStoragePath('Events');
    }

    public function testUntrackedStorageDirectoryIsNotClaimedAsANewFolder(): void
    {
        $this->storage->directory('Events', true);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A folder with that name already exists.');
        (new FolderModel($this->db))->availableStoragePath('Events');
    }

    public function testPermanentDeletionOfZeroFileFolderRemovesFinderMetadata(): void
    {
        $this->db->table('folder')->insert(['id' => 1, 'name' => 'dal-uy', 'path' => 'dal-uy']);
        $directory = $this->storage->directory('dal-uy', true);
        file_put_contents($directory . '/.DS_Store', 'Finder metadata');
        $this->trash->moveToTrash([], $this->actor, 'folder', 1, [1]);

        $this->assertSame(0, $this->trash->permanentlyRemoveFolder(['id' => 1, 'name' => 'dal-uy'], $this->actor));
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertSame(0, $this->db->table('folder')->countAllResults());
    }

    public function testPermanentDeletionRemovesFinderMetadataFromNestedFolders(): void
    {
        $this->db->table('folder')->insert(['id' => 1, 'name' => 'Events', 'path' => 'Events']);
        $this->db->table('folder')->insert(['id' => 2, 'name' => 'Nested', 'path' => 'Events/Nested', 'parent_id' => 1]);
        $directory = $this->storage->directory('Events/Nested', true);
        file_put_contents($directory . '/.DS_Store', 'nested metadata');
        file_put_contents(dirname($directory) . '/.DS_Store', 'root metadata');
        $file = $this->media(1, 'Events/Nested/photo.jpg', 2);
        $this->trash->moveToTrash([$file], $this->actor, 'folder', 1, [1, 2]);

        $this->assertSame(1, $this->trash->permanentlyRemoveFolder(['id' => 1, 'name' => 'Events'], $this->actor));
        $this->assertDirectoryDoesNotExist(dirname($directory));
        $this->assertSame(0, $this->db->table('folder')->countAllResults());
    }

    public function testPermanentDeletionPreservesUntrackedHiddenFiles(): void
    {
        $this->db->table('folder')->insert(['id' => 1, 'name' => 'Events', 'path' => 'Events']);
        $directory = $this->storage->directory('Events', true);
        file_put_contents($directory . '/.notes', 'keep this');
        $this->trash->moveToTrash([], $this->actor, 'folder', 1, [1]);
        try {
            $this->trash->permanentlyRemoveFolder(['id' => 1, 'name' => 'Events'], $this->actor);
            $this->fail('Untracked content must be preserved.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('untracked files or subfolders', $exception->getMessage());
            $this->assertStringNotContainsString($this->temporaryRoot, $exception->getMessage());
        }
        $this->assertSame('keep this', file_get_contents($directory . '/.notes'));
        $this->assertSame(1, $this->db->table('folder')->where('is_deleted', 1)->countAllResults());
    }

    public function testFinderMetadataSymlinksAreNotFollowedOrRemoved(): void
    {
        $this->db->table('folder')->insert(['id' => 1, 'name' => 'Events', 'path' => 'Events']);
        $directory = $this->storage->directory('Events', true);
        $target = $this->temporaryRoot . '/keep.txt';
        file_put_contents($target, 'keep this');
        symlink($target, $directory . '/.DS_Store');
        $this->trash->moveToTrash([], $this->actor, 'folder', 1, [1]);
        try {
            $this->trash->permanentlyRemoveFolder(['id' => 1, 'name' => 'Events'], $this->actor);
            $this->fail('Metadata symlinks must not be followed.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('untracked files or subfolders', $exception->getMessage());
        }
        $this->assertTrue(is_link($directory . '/.DS_Store'));
        $this->assertSame('keep this', file_get_contents($target));
    }
}
