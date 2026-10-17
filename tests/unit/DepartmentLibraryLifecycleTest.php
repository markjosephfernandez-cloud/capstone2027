<?php

use App\Services\StorageService;
use App\Services\TrashService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Config\Storage;

final class DepartmentLibraryLifecycleTest extends CIUnitTestCase
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
        $this->db->query('CREATE TABLE media_assignments (id INTEGER PRIMARY KEY, media_id INTEGER, department TEXT, delivery_filepath TEXT, status TEXT, sent_at TEXT, completed_at TEXT)');
        require_once APPPATH . 'Database/Migrations/2026-09-19-000001_AddIndependentDepartmentLibrary.php';
        (new \App\Database\Migrations\AddIndependentDepartmentLibrary(\Config\Database::forge($this->db)))->up();
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
        $image = imagecreatetruecolor(2, 2); imagejpeg($image, $physical); imagedestroy($image);
        $file = ['id' => $id, 'filename' => basename($path), 'filepath' => $this->storage->databasePath($physical), 'folder_id' => $folder, 'is_deleted' => 0];
        $this->db->table('files')->insert($file);
        return $file;
    }

    private function file(int $id): array
    {
        return $this->db->table('files')->where('id', $id)->get()->getRowArray();
    }

    private function deliver(array $file, bool $shared = false): array
    {
        $path = $this->storage->resolveDatabaseFile($file['filepath']);
        $copy = $this->storage->storeDepartmentCopy($path, 'CCS Department', 'Event');
        if ($shared) {
            $file['filepath'] = $this->storage->databasePath($copy['filepath']);
            $this->db->table('files')->where('id',$file['id'])->update(['filepath'=>$file['filepath']]);
        }
        $assignment = ['id'=>1,'media_id'=>$file['id'],'department'=>'CCS Department','status'=>'completed','delivery_filepath'=>$this->storage->databasePath($copy['filepath']),'sent_at'=>'2026-09-19 00:00:00'];
        $this->db->table('media_assignments')->insert($assignment);
        $created=[];
        $service=new \App\Services\DepartmentLibraryService($this->db,$this->storage);
        $id=$service->capture($assignment,$file,$created);
        return [$id,$file,$assignment,$created];
    }

    public function testDeliveredRecordAndCopySurviveAdminTrashAndPermanentDeletion(): void
    {
        $this->db->table('folder')->insert(['id'=>1,'name'=>'Event','path'=>'Event','is_deleted'=>0]);
        [$id,$file,$assignment]=$this->deliver($this->media(1,'Event/photo.jpg',1));
        $snapshot=$this->db->table('department_files')->where('id',$id)->get()->getRowArray();
        $copy=$this->storage->resolveDatabaseFile($snapshot['filepath']);
        $this->trash->moveToTrash([$file],$this->actor,'test',1,[1]);
        $this->assertSame(0,(int)$this->db->table('department_files')->where('id',$id)->get()->getRowArray()['is_deleted']);
        $this->assertSame(0,(int)$this->db->table('department_folders')->get()->getRowArray()['is_deleted']);
        $this->assertFileExists($copy);
        $this->trash->permanentlyRemoveMedia($this->file(1),$this->actor,'TEST');
        $this->assertSame(0,$this->db->table('files')->countAllResults());
        $this->assertSame(1,$this->db->table('department_files')->countAllResults());
        $this->assertSame(1,$this->db->table('media_assignments')->where('status','completed')->countAllResults());
        $this->assertFileExists($copy);
        $controller=new \App\Controllers\Web\WorkspaceController();
        $access=new ReflectionMethod($controller,'canAccessMedia');
        $this->assertTrue($access->invoke($controller,$snapshot,['role'=>'department','department'=>'CCS Department']));
        $this->assertFalse($access->invoke($controller,$snapshot,['role'=>'department','department'=>'CBA Department']));
        $this->assertSame($copy,(new ReflectionMethod($controller,'resolveMediaPathForActor'))->invoke($controller,$snapshot,['role'=>'department','department'=>'CCS Department']));
    }

    public function testRecoveryCopiesAreDetachedBeforeAdminDeletion(): void
    {
        [$id,$file,$assignment,$created]=$this->deliver($this->media(1,'photo.jpg'),true);
        $this->assertCount(1,$created);
        $snapshot=$this->db->table('department_files')->where('id',$id)->get()->getRowArray();
        $this->assertNotSame($file['filepath'],$snapshot['filepath']);
        $copy=$this->storage->resolveDatabaseFile($snapshot['filepath']);
        $this->trash->moveToTrash([$file],$this->actor,'test');
        $this->trash->permanentlyRemoveMedia($this->file(1),$this->actor,'TEST');
        $this->assertFileExists($copy);
        $this->assertSame('image/jpeg',(new finfo(FILEINFO_MIME_TYPE))->file($copy));
    }

    public function testBackfillDetachesLegacySharedCopyAlreadyInAdminTrash(): void
    {
        [$id, $file] = $this->deliver($this->media(1, 'photo.jpg'), true);
        // Recreate a pre-migration delivery: only its original assignment exists.
        $this->db->table('department_files')->where('id', $id)->delete();
        $this->trash->moveToTrash([$file], $this->actor, 'test');
        $result = (new \App\Services\DepartmentLibraryService($this->db, $this->storage))->backfill();
        $this->assertSame(1, $result['captured']);
        $snapshot = $this->db->table('department_files')->get()->getRowArray();
        $this->assertSame(1, (int) $this->file(1)['is_deleted']);
        $this->trash->permanentlyRemoveMedia($this->file(1), $this->actor, 'TEST');
        $this->assertFileExists($this->storage->resolveDatabaseFile($snapshot['filepath']));
    }

    public function testBackfillIsIdempotentAndDoesNotResurrectDepartmentDeletion(): void
    {
        [$id,$file,$assignment]=$this->deliver($this->media(1,'photo.jpg'));
        $this->db->table('department_files')->where('id',$id)->update(['is_deleted'=>1]);
        $service=new \App\Services\DepartmentLibraryService($this->db,$this->storage);
        $result=$service->backfill();
        $this->assertSame(1,$result['existing']);
        $this->assertSame(0,$result['captured']);
        $this->assertSame(1,(int)$this->db->table('department_files')->where('id',$id)->get()->getRowArray()['is_deleted']);
        $this->assertSame(0,(int)$this->file(1)['is_deleted']);
        $assignment['status']='pending';
        $created=[];
        $this->expectException(RuntimeException::class);
        $service->capture($assignment,$file,$created);
    }
}
