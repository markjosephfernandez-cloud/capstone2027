<?php

use App\Database\Migrations\AddCentralLibrary;
use App\Services\CentralMediaService;
use App\Services\ServerLibraryEditor;
use App\Services\ServerLibraryService;
use App\Services\StorageService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Storage;

require_once APPPATH.'Database/Migrations/2026-10-01-000001_AddCentralLibrary.php';

final class CentralLibraryTest extends CIUnitTestCase
{
    private string $root;
    private Storage $config;
    private $centralDb;
    private array $fixtureTables = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->centralDb = db_connect();
        self::assertSame('SQLite3', $this->centralDb->DBDriver);
        self::assertSame(':memory:', $this->centralDb->database);
        (new AddCentralLibrary())->up();
        $this->centralDb->resetDataCache();
        $this->root = sys_get_temp_dir().'/central-library-'.bin2hex(random_bytes(6));
        mkdir($this->root.'/Event/Photos',0770,true);
        file_put_contents($this->root.'/Event/Photos/photo.jpg','original image');
        $this->config = new class($this->root) extends Storage {
            public function __construct(private string $source) { parent::__construct(); }
            public function centralized(): bool { return true; }
            public function scanSourceRoot(): string { return $this->source; }
        };
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->fixtureTables) as $table) \Config\Database::forge()->dropTable($table,true);
        (new AddCentralLibrary())->down();
        $this->centralDb->resetDataCache();
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST) as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function testRecentIsPerUserAndKeepsIdentityAcrossRenameAndTrash(): void
    {
        $library = new ServerLibraryService($this->config);
        $central = new CentralMediaService($library);
        $editor = new ServerLibraryEditor($library,$this->config);
        $original = $central->identity('Event/Photos/photo.jpg');
        $central->viewed('Event/Photos/photo.jpg',12);
        $central->viewed('Event/Photos/photo.jpg',12);
        $central->viewed('Event/Photos/photo.jpg',13);
        self::assertSame(2,$this->centralDb->table('server_recent')->countAllResults());
        // Root rename avoids needing the legacy folder bridge in this focused fixture.
        $editor->move(['Event/Photos/photo.jpg'],'','renamed.jpg');
        $renamed = $central->identity('renamed.jpg');
        self::assertSame($original['id'],$renamed['id']);
        self::assertSame('original image',file_get_contents($this->root.'/renamed.jpg'));
        $editor->trash(['renamed.jpg'],12);
        self::assertSame(1,(int)$this->centralDb->table('server_media')->where('id',$original['id'])->get()->getRowArray()['is_deleted']);
        $editor->restore($editor->trashItems()[0]['id']);
        self::assertSame(0,(int)$central->identity('renamed.jpg')['is_deleted']);
        self::assertSame($original['id'],$central->identity('renamed.jpg')['id']);
        self::assertSame(2,$this->centralDb->table('server_recent')->countAllResults());
    }

    public function testFileTrashUsesIndexedLookupsAndFolderTrashStillIncludesDescendants(): void
    {
        $db = $this->centralDb;
        $forge = \Config\Database::forge();
        foreach (['server_library_index', 'server_library_scan_queue'] as $table) {
            $forge->addField([
                'source_key' => ['type' => 'VARCHAR', 'constraint' => 64],
                'path_hash' => ['type' => 'VARCHAR', 'constraint' => 64],
                'path' => ['type' => 'TEXT'],
            ]);
            $forge->createTable($table);
            $this->fixtureTables[] = $table;
        }
        $library = new ServerLibraryService($this->config);
        $central = new CentralMediaService($library);
        $editor = new ServerLibraryEditor($library, $this->config);
        $file = 'Event/Photos/photo.jpg';
        $identity = $central->identity($file);
        foreach ([$file, 'Event/Photos/other.jpg', 'Event/Photos-extra/keep.jpg'] as $path) {
            foreach (['server_library_index', 'server_library_scan_queue'] as $table) {
                $db->table($table)->insert(['source_key' => $library->catalogSourceKey(), 'path_hash' => hash('sha256', $path), 'path' => $path]);
            }
        }
        $queries = [];
        $listener = static function ($query) use (&$queries): void { $queries[] = (string) $query; };
        \CodeIgniter\Events\Events::on('DBQuery', $listener);
        try {
            $editor->trash([$file], 12);
        } finally {
            \CodeIgniter\Events\Events::removeListener('DBQuery', $listener);
        }
        $mediaReads = array_values(array_filter($queries, static fn ($sql) => str_starts_with($sql, 'SELECT') && str_contains($sql, 'server_media') && str_contains($sql, 'LIKE')));
        self::assertCount(1, $mediaReads);
        self::assertMatchesRegularExpression('/[\x60"]?id[\x60"]? IN \(/', $mediaReads[0]);
        $catalogDeletes = array_values(array_filter($queries, static fn ($sql) => str_starts_with($sql, 'DELETE') && str_contains($sql, 'server_library_')));
        self::assertCount(2, $catalogDeletes);
        foreach ($catalogDeletes as $sql) {
            self::assertStringContainsString('path_hash', $sql);
            self::assertStringNotContainsString('LIKE', $sql);
        }
        self::assertSame(1, (int) $db->table('server_media')->where('id', $identity['id'])->get()->getRowArray()['is_deleted']);
        foreach (['server_library_index', 'server_library_scan_queue'] as $table) {
            self::assertSame(2, $db->table($table)->countAllResults());
        }
        file_put_contents($this->root . '/Event/Photos/other.jpg', 'other image');
        $other = $central->identity('Event/Photos/other.jpg');
        $editor->trash(['Event/Photos'], 12);
        self::assertSame(1, (int) $db->table('server_media')->where('id', $other['id'])->get()->getRowArray()['is_deleted']);
        foreach (['server_library_index', 'server_library_scan_queue'] as $table) {
            self::assertSame(['Event/Photos-extra/keep.jpg'], array_column($db->table($table)->get()->getResultArray(), 'path'));
        }
        self::assertCount(2, $editor->state()['trash']);
    }

    public function testMetadataWritesUseDatabaseAndPreserveOriginal(): void
    {
        $editor = new ServerLibraryEditor(new ServerLibraryService($this->config),$this->config);
        $editor->addTags(['Event/Photos/photo.jpg'],['Digital Expo']);
        self::assertSame(['Digital Expo'],$editor->state()['tags']['Event/Photos/photo.jpg']);
        self::assertSame(1,$this->centralDb->table('server_metadata')->countAllResults());
        self::assertFileDoesNotExist($this->root.'/.greyhound-library/metadata.json');
        self::assertSame('original image',file_get_contents($this->root.'/Event/Photos/photo.jpg'));
    }

    public function testExternalTrashDeletionRemovesMissingOriginalFromFileAndCatalogListings(): void
    {
        $this->distributionFixtures();
        $forge = \Config\Database::forge();
        $forge->addField(['source_key'=>['type'=>'TEXT'], 'path'=>['type'=>'TEXT'], 'path_hash'=>['type'=>'TEXT'], 'name'=>['type'=>'TEXT']]);
        $forge->createTable('server_library_index');
        $this->fixtureTables[] = 'server_library_index';
        $library = new ServerLibraryService($this->config);
        $fileId = (new CentralMediaService($library))->register('Event/Photos/photo.jpg');
        $this->centralDb->table('server_library_index')->insert([
            'source_key'=>$library->catalogSourceKey(), 'path'=>'Event/Photos/photo.jpg', 'path_hash'=>hash('sha256', 'Event/Photos/photo.jpg'), 'name'=>'photo.jpg',
        ]);
        // Simulate an older Trash item with no surviving manifest.
        mkdir($this->root . '/TRASH');
        $stored = str_repeat('a', 32) . '--photo.jpg';
        rename($this->root . '/Event/Photos/photo.jpg', $this->root . '/TRASH/' . $stored);
        $queries = [];
        $listener = static function ($query) use (&$queries): void { $queries[] = (string) $query; };
        \CodeIgniter\Events\Events::on('DBQuery', $listener);
        try {
            (new ServerLibraryEditor($library, $this->config))->permanentlyDelete('external-' . bin2hex($stored));
        } finally {
            \CodeIgniter\Events\Events::removeListener('DBQuery', $listener);
        }
        $lookups = array_values(array_filter($queries, static fn ($sql) =>
            (str_starts_with($sql, 'SELECT') && str_contains($sql, 'server_media'))
            || (str_starts_with($sql, 'DELETE') && str_contains($sql, 'server_library_index'))));
        self::assertCount(2, $lookups);
        foreach ($lookups as $sql) {
            self::assertStringContainsString('path_hash', $sql);
            self::assertStringNotContainsString('LIKE', $sql);
        }
        self::assertFileDoesNotExist($this->root . '/TRASH/' . $stored);
        self::assertSame(1, (int) $this->centralDb->table('files')->where('id', $fileId)->get()->getRowArray()['is_deleted']);
        self::assertSame(1, (int) $this->centralDb->table('server_media')->where('file_id', $fileId)->get()->getRowArray()['is_deleted']);
        self::assertSame(0, $this->centralDb->table('server_library_index')->countAllResults());
    }

    public function testFolderReconciliationStillDeletesDescendantsAndKeepsAdjacentPaths(): void
    {
        $library = new ServerLibraryService($this->config);
        $central = new CentralMediaService($library);
        $child = $central->identity('Event/Photos/photo.jpg');
        $adjacent = $central->identity('Event/Photos-extra/keep.jpg');
        unlink($this->root . '/Event/Photos/photo.jpg');
        rmdir($this->root . '/Event/Photos');
        $reconciler = new \App\Services\ServerTrashReconciler($library);
        self::assertSame(['Event/Photos'], $reconciler->reconcile(['path'=>'Event/Photos', 'directory'=>true]));
        self::assertSame(1, (int) $this->centralDb->table('server_media')->where('id', $child['id'])->get()->getRowArray()['is_deleted']);
        self::assertSame(0, (int) $this->centralDb->table('server_media')->where('id', $adjacent['id'])->get()->getRowArray()['is_deleted']);
    }

    public function testTrashReconciliationPreservesLiveCopiesAndAmbiguousNames(): void
    {
        $this->distributionFixtures();
        $library = new ServerLibraryService($this->config);
        $central = new CentralMediaService($library);
        $first = $central->register('Event/Photos/photo.jpg');
        $reconciler = new \App\Services\ServerTrashReconciler($library);
        $record = ['original_unknown'=>true, 'stored_name'=>str_repeat('a', 32) . '--photo.jpg'];
        self::assertSame([], $reconciler->reconcile($record));
        file_put_contents($this->root . '/Event/photo.jpg', 'another copy');
        $second = $central->register('Event/photo.jpg');
        unlink($this->root . '/Event/Photos/photo.jpg');
        self::assertSame([], $reconciler->reconcile($record));
        self::assertSame(2, $this->centralDb->table('files')->whereIn('id', [$first, $second])->where('is_deleted', 0)->countAllResults());
        self::assertSame('another copy', file_get_contents($this->root . '/Event/photo.jpg'));
        self::assertSame([], $reconciler->reconcile(['path'=>'Unavailable/photo.jpg']));
    }

    public function testStorageAliasResolvesOriginalAndRejectsTraversal(): void
    {
        $storage = new StorageService($this->config);
        self::assertSame(realpath($this->root.'/Event/Photos/photo.jpg'),$storage->resolveDatabaseFile('server-library/Event/Photos/photo.jpg'));
        $this->expectException(RuntimeException::class);
        $storage->resolveDatabaseFile('server-library/../outside.jpg');
    }

    public function testStorageRejectsSymbolicLinks(): void
    {
        symlink($this->root.'/Event/Photos',$this->root.'/linked');
        $this->expectException(RuntimeException::class);
        (new StorageService($this->config))->resolveDatabaseFile('server-library/linked/photo.jpg');
    }

    public function testSourceLibraryCannotAccessDepartmentCopies(): void
    {
        mkdir($this->root.'/DEPARTMENT/CCS',0770,true);
        file_put_contents($this->root.'/DEPARTMENT/CCS/photo.jpg','department image');
        $this->expectException(RuntimeException::class);
        (new ServerLibraryService($this->config))->resolve('DEPARTMENT/CCS/photo.jpg');
    }

    private function distributionFixtures(bool $fail = false): object
    {
        $definitions = [
            'files'=>['filename','display_title','filepath','filetype','mime_type','size','media_kind','folder_id','employee_id','dateupload','is_deleted','last_action','last_action_at'],
            'folder'=>['name','path','parent_id','is_deleted','created_at'],
            'user'=>['role','department','is_active','employee_id'],
            'media_requests'=>['department','status','current_cycle','transaction_type','transaction_code','title','description','authorization_status','requested_by','original_sender','submitted_at','transmitted_by','transmitted_at','updated_at','completion_feedback','completed_by','completed_at'],
            'distribution_actions'=>['request_id','assignment_id','action','cycle_number','transaction_status','authorization_status','feedback','items_json','actor_id','actor_name','actor_role','department','file_count','created_at'],
            'notifications'=>['recipient_id','recipient_role','type','title','message','link','is_read','created_at'],
            'media_assignments'=>['request_id','media_id','status','cycle_number','delivery_filepath','department','method','submission_type','sent_by','sent_at','completed_by','completed_at'],
            'media_assignment_folders'=>['request_id','folder_id','status','cycle_number','delivery_path','department','method','submission_type','sent_by','sent_at','completed_by','completed_at'],
        ];
        foreach ($definitions as $table=>$columns) {
            $forge = \Config\Database::forge();
            self::assertFalse($this->centralDb->tableExists($table));
            $fields = ['id'=>['type'=>'INTEGER','auto_increment'=>true]];
            foreach ($columns as $column) $fields[$column] = ['type'=>'TEXT','null'=>true];
            $forge->addField($fields); $forge->addKey('id',true); $forge->createTable($table);
            $this->fixtureTables[] = $table;
        }
        $this->centralDb->resetDataCache();
        $this->centralDb->table('user')->insert(['role'=>'department','department'=>'CCS','is_active'=>1]);
        $distribution = new class($this->centralDb,$fail) {
            public array $files = [];
            public array $folders = [];
            public function __construct(private $db, public bool $fail) {}
            public function assign($files,$folders,$department,$request,$method,$actor): array {
                if ($this->fail) throw new RuntimeException('Simulated transaction failure');
                $this->files = $files; $this->folders = $folders;
                $cycle = $request === null ? 1 : 1+(int)$this->db->table('media_requests')->where('id',$request)->get()->getRowArray()['current_cycle'];
                $this->db->table('media_requests')->upsert(['id'=>7,'department'=>$department,'status'=>'pending','current_cycle'=>$cycle]);
                foreach ($files as $id) $this->db->table('media_assignments')->insert(['request_id'=>7,'media_id'=>$id,'cycle_number'=>$cycle,'status'=>'pending']);
                foreach ($folders as $id) $this->db->table('media_assignment_folders')->insert(['request_id'=>7,'folder_id'=>$id,'cycle_number'=>$cycle,'status'=>'pending']);
                return ['request_id'=>7,'cycle'=>$cycle];
            }
        };
        \Config\Services::injectMock('distribution',$distribution);
        return $distribution;
    }

    public function testDistributionCopiesOnlySelectedRootAndItsInternalTree(): void
    {
        $distribution = $this->distributionFixtures();
        mkdir($this->root.'/Event/Photos/Nested');
        file_put_contents($this->root.'/Event/Photos/Nested/video.mov','video original');
        $service = new \App\Services\CentralDistributionService(new ServerLibraryService($this->config),new StorageService($this->config));
        $service->deliver(['Event/Photos/photo.jpg'],['Event/Photos'],'CCS',['role'=>'admin','employee_id'=>12]);
        self::assertSame('original image',file_get_contents($this->root.'/DEPARTMENT/CCS/Photos/photo.jpg'));
        self::assertSame('video original',file_get_contents($this->root.'/DEPARTMENT/CCS/Photos/Nested/video.mov'));
        self::assertDirectoryDoesNotExist($this->root.'/DEPARTMENT/CCS/Event');
        self::assertCount(2,$distribution->files);
        self::assertCount(1,$distribution->folders);
        file_put_contents($this->root.'/DEPARTMENT/CCS/Photos/photo.jpg','department edit');
        self::assertSame('original image',file_get_contents($this->root.'/Event/Photos/photo.jpg'));
        self::assertSame('server-library/DEPARTMENT/CCS/Photos/photo.jpg',$this->centralDb->table('media_assignments')->where('media_id',$distribution->files[0])->get()->getRowArray()['delivery_filepath']);
    }

    public function testFolderDistributionSkipsMetadataAndUnsupportedNamesLikeThePicker(): void
    {
        $distribution = $this->distributionFixtures();
        mkdir($this->root . '/Event/Photos/Nested');
        foreach (['Event/Photos', 'Event/Photos/Nested'] as $folder) {
            foreach (["Icon\r", '.DS_Store', 'Thumbs.db', 'desktop.ini', 'invalid\\name.jpg'] as $name) {
                file_put_contents($this->root . '/' . $folder . '/' . $name, 'metadata');
            }
            mkdir($this->root . '/' . $folder . '/__MACOSX');
            file_put_contents($this->root . '/' . $folder . '/__MACOSX/resource', 'metadata');
        }
        file_put_contents($this->root . '/Event/Photos/Nested/Icon', 'ordinary extensionless file');
        $library = new ServerLibraryService($this->config);
        $selection = (new \App\Services\ServerDistributionPicker($library))->selection([], [bin2hex('Event/Photos')]);
        self::assertCount(2, $selection['files']);
        $service = new \App\Services\CentralDistributionService($library, new StorageService($this->config));
        $result = $service->deliver(array_column($selection['files'], 'path'), ['Event/Photos'], 'CCS', ['role'=>'admin', 'employee_id'=>12]);
        self::assertSame(2, $result['copied']);
        self::assertCount(2, $distribution->files);
        self::assertSame('original image', file_get_contents($this->root . '/DEPARTMENT/CCS/Photos/photo.jpg'));
        self::assertSame('ordinary extensionless file', file_get_contents($this->root . '/DEPARTMENT/CCS/Photos/Nested/Icon'));
        foreach (['Photos', 'Photos/Nested'] as $folder) {
            foreach (["Icon\r", '.DS_Store', 'Thumbs.db', 'desktop.ini', 'invalid\\name.jpg', '__MACOSX'] as $name) {
                self::assertFileDoesNotExist($this->root . '/DEPARTMENT/CCS/' . $folder . '/' . $name);
            }
        }
        self::assertFileExists($this->root . '/Event/Photos/' . "Icon\r");
    }

    public function testFailedDistributionRemovesNewCopiesAndKeepsOriginals(): void
    {
        $this->distributionFixtures(true);
        $service = new \App\Services\CentralDistributionService(new ServerLibraryService($this->config),new StorageService($this->config));
        try { $service->deliver([],['Event/Photos'],'CCS',['role'=>'admin','employee_id'=>12]); self::fail('Expected failure'); }
        catch (RuntimeException $error) { self::assertSame('Simulated transaction failure',$error->getMessage()); }
        self::assertDirectoryDoesNotExist($this->root.'/DEPARTMENT/CCS/Photos');
        self::assertFileExists($this->root.'/Event/Photos/photo.jpg');
        self::assertSame(0,$this->centralDb->table('media_assignments')->countAllResults());
    }

    public function testMigrationPreservesIdsOriginalBytesAndCanBeRepeated(): void
    {
        $this->distributionFixtures();
        $legacyRoot = $this->root.'/.legacy';
        mkdir($legacyRoot.'/Event',0770,true);
        file_put_contents($legacyRoot.'/Event/photo.jpg','legacy original');
        $config = new class($legacyRoot) extends Storage {
            public function __construct(private string $directory) { parent::__construct(); }
            public function centralized(): bool { return false; }
            public function root(): string { return $this->directory; }
            public function departmentRoot(): string { return $this->directory.'/Departments'; }
        };
        $this->centralDb->table('files')->insert(['id'=>42,'filename'=>'photo.jpg','filepath'=>'writable/uploads/Event/photo.jpg','is_deleted'=>0]);
        $this->centralDb->table('folder')->insert(['id'=>19,'name'=>'Event','path'=>'Event','is_deleted'=>0]);
        $migration = new \App\Services\CentralMigrationService($this->config,new StorageService($config));
        $preview = $migration->run();
        self::assertSame([],$preview['missing']);
        self::assertFileDoesNotExist($this->root.'/Imported media/Event/photo.jpg');
        $report = $migration->run(true);
        self::assertSame(2,$report['verified']);
        self::assertSame('legacy original',file_get_contents($legacyRoot.'/Event/photo.jpg'));
        self::assertSame('legacy original',file_get_contents($this->root.'/Imported media/Event/photo.jpg'));
        $file = $this->centralDb->table('files')->where('id',42)->get()->getRowArray();
        self::assertSame('server-library/Imported media/Event/photo.jpg',$file['filepath']);
        self::assertSame(42,(int)$this->centralDb->table('server_media')->where('path','Imported media/Event/photo.jpg')->get()->getRowArray()['file_id']);
        self::assertSame(2,$migration->run(true)['verified']);
        self::assertSame(1,$this->centralDb->table('files')->countAllResults());
    }

    public function testResubmissionPreservesPreviousReviewCopyAndOriginalIdentity(): void
    {
        $this->distributionFixtures();
        $storage = new StorageService($this->config);
        $service = new \App\Services\CentralDistributionService(new ServerLibraryService($this->config),$storage);
        $actor = ['role'=>'admin','employee_id'=>12];
        $service->deliver([],['Event/Photos'],'CCS',$actor);
        $this->centralDb->table('media_requests')->where('id',7)->update(['status'=>'pending_incomplete']);
        file_put_contents($this->root.'/Event/Photos/photo.jpg','corrected original');
        $service->deliver([],['Event/Photos'],'CCS',$actor,7);
        $rows = $this->centralDb->table('media_assignments')->orderBy('id')->get()->getResultArray();
        self::assertCount(2,$rows);
        self::assertSame($rows[0]['media_id'],$rows[1]['media_id']);
        self::assertSame('original image',file_get_contents($storage->resolveDatabaseFile($rows[0]['delivery_filepath'])));
        self::assertSame('corrected original',file_get_contents($storage->resolveDatabaseFile($rows[1]['delivery_filepath'])));
        self::assertSame('server-library/DEPARTMENT/CCS/Photos/photo.jpg',$rows[1]['delivery_filepath']);
    }

    public function testFailedResubmissionRestoresPreviousPendingCopy(): void
    {
        $distribution = $this->distributionFixtures();
        $service = new \App\Services\CentralDistributionService(new ServerLibraryService($this->config),new StorageService($this->config));
        $actor = ['role'=>'admin','employee_id'=>12];
        $service->deliver([],['Event/Photos'],'CCS',$actor);
        $this->centralDb->table('media_requests')->where('id',7)->update(['status'=>'pending_incomplete']);
        file_put_contents($this->root.'/Event/Photos/photo.jpg','corrected original');
        $distribution->fail = true;
        try { $service->deliver([],['Event/Photos'],'CCS',$actor,7); self::fail('Expected failure'); }
        catch (RuntimeException $error) { self::assertSame('Simulated transaction failure',$error->getMessage()); }
        self::assertSame('original image',file_get_contents($this->root.'/DEPARTMENT/CCS/Photos/photo.jpg'));
        self::assertSame('corrected original',file_get_contents($this->root.'/Event/Photos/photo.jpg'));
        self::assertSame(1,$this->centralDb->table('media_assignments')->countAllResults());
        self::assertSame('server-library/DEPARTMENT/CCS/Photos/photo.jpg',$this->centralDb->table('media_assignments')->get()->getRowArray()['delivery_filepath']);
    }
    public function testFullDepartmentReviewLifecycleKeepsIndependentCopy(): void
    {
        $this->distributionFixtures();
        require_once APPPATH.'Database/Migrations/2026-09-19-000001_AddIndependentDepartmentLibrary.php';
        (new \App\Database\Migrations\AddIndependentDepartmentLibrary())->up();
        $this->fixtureTables[] = 'department_files';
        $this->fixtureTables[] = 'department_folders';
        $this->centralDb->resetDataCache();
        $previous = $_ENV['greyhound.centralLibrary'] ?? null;
        $_ENV['greyhound.centralLibrary'] = 'true';
        try {
            $storage = new StorageService($this->config);
            \Config\Services::injectMock('storage',$storage);
            $distribution = new \App\Services\DistributionService();
            \Config\Services::injectMock('distribution',$distribution);
            $service = new \App\Services\CentralDistributionService(new ServerLibraryService($this->config),$storage);
            $admin = ['role'=>'admin','employee_id'=>12];
            $department = ['role'=>'department','employee_id'=>13,'department'=>'CCS'];
            mkdir($this->root . '/Earlier');
            file_put_contents($this->root . '/Earlier/first.jpg', 'first cycle image');
            file_put_contents($this->root . '/Event/Photos/removed.jpg', 'superseded image');
            $sent = $service->deliver([],['Event/Photos', 'Earlier'],'CCS',$admin);
            $distribution->markIncomplete($sent['request_id'],$department,'Please correct the photo.');
            file_put_contents($this->root.'/Event/Photos/photo.jpg','corrected original');
            unlink($this->root . '/Event/Photos/removed.jpg');
            $service->deliver([],['Event/Photos'],'CCS',$admin,$sent['request_id']);
            $distribution->authorize($sent['request_id'],$department,'Corrections approved.');
            self::assertSame(2, $this->centralDb->table('department_files')->countAllResults());
            self::assertSame(1, $this->centralDb->table('department_files')->where('filename', 'first.jpg')->countAllResults());
            self::assertSame(0, $this->centralDb->table('department_files')->where('filename', 'removed.jpg')->countAllResults());
            self::assertSame(1, $this->centralDb->table('department_folders')->where('name', 'Earlier')->countAllResults());
            $snapshot = $this->centralDb->table('department_files')->where('filename', 'photo.jpg')->get()->getRowArray();
            self::assertNotNull($snapshot);
            self::assertSame('server-library/DEPARTMENT/CCS/Photos/photo.jpg',$snapshot['filepath']);
            self::assertSame('complete',$this->centralDb->table('media_requests')->where('id',$sent['request_id'])->get()->getRowArray()['status']);
            self::assertSame(['original','marked_incomplete','resubmission','confirmed_complete'],array_column($this->centralDb->table('distribution_actions')->orderBy('id')->get()->getResultArray(),'action'));
            unlink($this->root.'/Event/Photos/photo.jpg');
            self::assertSame('corrected original',file_get_contents($storage->resolveDatabaseFile($snapshot['filepath'])));
            self::assertSame('Photos',$this->centralDb->table('department_folders')->where('id',$snapshot['folder_id'])->get()->getRowArray()['name']);
            mkdir($this->root.'/Empty');
            $empty = $service->deliver([],['Empty'],'CCS',$admin);
            $distribution->authorize($empty['request_id'],$department,'Empty folder accepted.');
            self::assertSame(1,$this->centralDb->table('department_folders')->where('name','Empty')->countAllResults());
            self::assertSame(2,$this->centralDb->table('department_files')->countAllResults());
        } finally {
            if ($previous === null) unset($_ENV['greyhound.centralLibrary']); else $_ENV['greyhound.centralLibrary'] = $previous;
        }
    }

    public function testServerUploadQueuesAiAndCommitsMetadataWithoutCallingTheModel(): void
    {
        $this->distributionFixtures();
        $temporary = $this->root.'/.upload.png';
        $image = imagecreatetruecolor(3,4);
        imagepng($image,$temporary); imagedestroy($image);
        $service = new class(new ServerLibraryService($this->config),$this->config) extends \App\Services\ServerLibraryUploadService {
            protected function analyze(\CodeIgniter\HTTP\Files\UploadedFile $file,string $folder): array { throw new RuntimeException('Upload must not wait for AI'); }
        };
        $result = $service->receive(new \App\Libraries\AssembledUpload($temporary,'new.png'),
            ['folder_id'=>'Event/Photos','upload_batch_id'=>'batch12345'],['employee_id'=>12]);
        self::assertTrue($result['ok']);
        self::assertSame(hash_file('sha256',$temporary),hash_file('sha256',$this->root.'/Event/Photos/new.png'));
        $file = $this->centralDb->table('files')->get()->getRowArray();
        self::assertSame('server-library/Event/Photos/new.png',$file['filepath']);
        self::assertSame('pending',$this->centralDb->table('server_analysis_jobs')->get()->getRowArray()['status']);
        $media = $this->centralDb->table('server_media')->where('file_id',$file['id'])->get()->getRowArray();
        $details = json_decode($media['metadata_json'],true);
        self::assertSame(3,$details['width']); self::assertSame(4,$details['height']);
        self::assertSame('Unclassified Event',$details['classification']);
        self::assertFileDoesNotExist($this->root.'/.greyhound-library/metadata.json');
    }

    public function testJpegUploadWithInvalidUtf8ExifCommitsOriginalAndQueuesAnalysis(): void
    {
        if (!function_exists('exif_read_data')) self::markTestSkipped('EXIF extension required.');
        $this->distributionFixtures();
        $temporary = $this->root.'/.upload.jpg';
        $image = imagecreatetruecolor(3,4);
        imagejpeg($image,$temporary); imagedestroy($image);
        // Little-endian TIFF with one ImageDescription containing invalid UTF-8.
        $description = "Camera \xFF\xFE photo\0";
        $tiff = 'II'.pack('vV',42,8).pack('v',1)
            .pack('vvVV',0x010E,2,strlen($description),26).pack('V',0).$description;
        $exif = "Exif\0\0".$tiff;
        $jpeg = file_get_contents($temporary);
        file_put_contents($temporary,substr($jpeg,0,2)."\xFF\xE1".pack('n',strlen($exif)+2).$exif.substr($jpeg,2));
        self::assertSame(rtrim($description,"\0"),exif_read_data($temporary)['ImageDescription']);

        $service = new \App\Services\ServerLibraryUploadService(new ServerLibraryService($this->config),$this->config);
        $result = $service->receive(new \App\Libraries\AssembledUpload($temporary,'camera.jpg'),
            ['folder_id'=>'Event/Photos','upload_batch_id'=>'batch12345'],['employee_id'=>12]);

        self::assertTrue($result['ok']);
        self::assertSame(hash_file('sha256',$temporary),hash_file('sha256',$this->root.'/Event/Photos/camera.jpg'));
        self::assertSame('pending',$this->centralDb->table('server_analysis_jobs')->get()->getRowArray()['status']);
        $file = $this->centralDb->table('files')->where('filename','camera.jpg')->get()->getRowArray();
        $media = $this->centralDb->table('server_media')->where('file_id',$file['id'])->get()->getRowArray();
        $details = json_decode($media['metadata_json'],true,512,JSON_THROW_ON_ERROR);
        self::assertSame("Camera \u{FFFD}\u{FFFD} photo",$details['exif']['ImageDescription']);
        self::assertSame(3,$details['width']);
        self::assertSame(4,$details['height']);
    }

    public function testReusedFilenameDoesNotReuseTrashedIdentity(): void
    {
        $library = new ServerLibraryService($this->config);
        $central = new CentralMediaService($library);
        $editor = new ServerLibraryEditor($library,$this->config);
        $old = $central->identity('Event/Photos/photo.jpg');
        $editor->trash(['Event/Photos/photo.jpg'],12);
        $oldTrash = array_key_first($editor->state()['trash']);
        file_put_contents($this->root.'/Event/Photos/photo.jpg','replacement');
        $replacement = $central->identity('Event/Photos/photo.jpg');
        self::assertNotSame($old['id'],$replacement['id']);
        $editor->trash(['Event/Photos/photo.jpg'],12);
        $editor->restore($oldTrash);
        self::assertSame('original image',file_get_contents($this->root.'/Event/Photos/photo.jpg'));
        self::assertSame($old['id'],$central->identity('Event/Photos/photo.jpg')['id']);
        self::assertSame(1,(int)$this->centralDb->table('server_media')->where('id',$replacement['id'])->get()->getRowArray()['is_deleted']);
    }

    public function testViewTrackingVerifiesCsrfWithoutInvalidatingOpenForms(): void
    {
        $config = config('Security');
        $config->regenerate = true;
        $security = new \CodeIgniter\Security\Security($config);
        \Config\Services::injectMock('security',$security);
        $request = service('request');
        $request->setMethod('POST');
        $hash = $security->getHash();
        $request->setGlobal('post',[$security->getTokenName()=>$hash]);
        (new \App\Filters\RecentViewCsrf())->before($request);
        self::assertSame($hash,$security->getHash());
        self::assertTrue($config->regenerate);
        $request->setGlobal('post',[]);
        $this->expectException(\CodeIgniter\Security\Exceptions\SecurityException::class);
        (new \App\Filters\RecentViewCsrf())->before($request);
    }

    public function testCentralServerPageRendersWithoutLocalLibraryTab(): void
    {
        $previous = $_ENV['greyhound.centralLibrary'] ?? null;
        $_ENV['greyhound.centralLibrary'] = 'true';
        try {
            $actor = ['isLoggedIn'=>true,'role'=>'admin','name'=>'Test admin'];
            $session = $this->createMock(\CodeIgniter\Session\Session::class);
            $session->method('get')->willReturnCallback(static fn ($key=null) => $key === null ? $actor : ($actor[$key] ?? null));
            \Config\Services::injectMock('session',$session);
            $controller = new class(new ServerLibraryService($this->config)) extends \App\Controllers\Web\ServerLibraryController {
                public function __construct(private ServerLibraryService $source) {}
                protected function library(): ServerLibraryService { return $this->source; }
                protected function catalog(): ServerLibraryService { return $this->source; }
            };
            $request = new \CodeIgniter\HTTP\IncomingRequest(config('App'),new \CodeIgniter\HTTP\URI('http://example.com/'),null,new \CodeIgniter\HTTP\UserAgent());
            $request->setGlobal('get',['folder'=>'Event/Photos']);
            $controller->initController($request,new \CodeIgniter\HTTP\Response(config('App')),service('logger'));
            $html = $controller->index();
            self::assertStringContainsString('Server Library',$html);
            self::assertStringContainsString('photo.jpg',$html);
            self::assertStringNotContainsString('Local Library',$html);
            self::assertStringContainsString('greyhoundRecordViewed',$html);
        } finally {
            if ($previous === null) unset($_ENV['greyhound.centralLibrary']); else $_ENV['greyhound.centralLibrary'] = $previous;
        }
    }

    public function testCleanupDeletesOnlyRecordedGeneratedTemporaryFiles(): void
    {
        $root = realpath($this->root);
        mkdir($root.'/Imported media');
        $temporary = $root.'/Imported media/.migration-'.str_repeat('a',24);
        $original = $root.'/Imported media/photo.jpg';
        file_put_contents($temporary,'temporary'); file_put_contents($original,'original');
        $ledger = $root.'/.cleanup.json';
        file_put_contents($ledger,json_encode([$temporary,$original]));
        $result = (new \App\Services\CentralMigrationCleanup($this->config,$ledger))->run();
        self::assertSame(1,$result['removed']);
        self::assertSame(1,$result['remaining']);
        self::assertFileDoesNotExist($temporary);
        self::assertSame('original',file_get_contents($original));
    }

}
