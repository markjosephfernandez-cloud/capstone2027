<?php

use App\Services\DepartmentMediaRecoveryService;
use App\Services\StorageService;
use CodeIgniter\Test\CIUnitTestCase;

final class DepartmentMediaRecoveryTest extends CIUnitTestCase
{
    private string $root;
    private $recoveryDb;
    private DepartmentMediaRecoveryService $recovery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/greyhound-recovery-' . bin2hex(random_bytes(6));
        foreach (['uploads', 'Department/CCS/Event', 'Department/CBA'] as $dir) mkdir($this->root . '/' . $dir, 0775, true);
        $config = new class extends \Config\Storage {
            public string $testRoot;
            public function root(): string { return $this->testRoot . '/uploads'; }
            public function departmentRoot(): string { return $this->testRoot . '/Department'; }
            public function legacyRoots(): array { return []; }
        };
        $config->testRoot = $this->root;
        $this->recoveryDb = db_connect(['DBDriver'=>'SQLite3', 'database'=>':memory:', 'DBPrefix'=>'', 'DBDebug'=>true], false);
        $this->recoveryDb->query('CREATE TABLE user (department TEXT, role TEXT)');
        $this->recoveryDb->query('CREATE TABLE files (id INTEGER PRIMARY KEY AUTOINCREMENT, filename TEXT, filepath TEXT, content_hash TEXT, is_deleted INTEGER, filetype TEXT, mime_type TEXT, size INTEGER, folder_id INTEGER, dateupload TEXT, downloadable INTEGER, description TEXT)');
        $this->recoveryDb->query('CREATE TABLE folder (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, path TEXT, parent_id INTEGER, is_deleted INTEGER, created_at TEXT, description TEXT)');
        $this->recoveryDb->query('CREATE TABLE media_assignments (id INTEGER PRIMARY KEY AUTOINCREMENT, media_id INTEGER, department TEXT, delivery_filepath TEXT, request_id INTEGER, method TEXT, status TEXT, sent_by INTEGER, sent_at TEXT, completed_at TEXT)');
        foreach (['CCS Department', 'CBA Department'] as $department) $this->recoveryDb->table('user')->insert(['department'=>$department,'role'=>'department']);
        $this->recovery = new DepartmentMediaRecoveryService($this->recoveryDb, new StorageService($config), $config->departmentRoot());
    }

    private function photo(string $relative, int $color = 0): string
    {
        $path = $this->root . '/' . $relative;
        $image = imagecreatetruecolor(2, 2);
        imagefill($image, 0, 0, $color);
        imagepng($image, $path);
        imagedestroy($image);
        return $path;
    }

    public function testRecoveryReconnectsMatchesRegistersCopiesAndIsIdempotent(): void
    {
        $original = $this->photo('uploads/original.png');
        copy($original, $this->root . '/Department/CCS/Event/copy.png');
        $missing = $this->photo('Department/CBA/only-copy.png', 255);
        $this->recoveryDb->table('files')->insert(['filename'=>'original.png','filepath'=>'writable/uploads/original.png','content_hash'=>hash_file('sha256',$original),'is_deleted'=>0]);
        $plan = $this->recovery->plan();
        $this->assertEqualsCanonicalizing(['assign','register'], array_column($plan,'action'));
        $this->assertSame(1, $this->recoveryDb->table('files')->countAllResults());
        $created = $this->recovery->apply($plan, $this->root . '/manifest.json');
        $this->assertCount(1, $created['file_ids']);
        $this->assertCount(2, $created['assignment_ids']);
        $ccs = $this->recoveryDb->table('media_assignments')->where('department','CCS Department')->get()->getRowArray();
        $cba = $this->recoveryDb->table('media_assignments')->where('department','CBA Department')->get()->getRowArray();
        $this->assertSame(1, (int)$ccs['media_id']);
        $this->assertNotSame($ccs['media_id'], $cba['media_id']);
        $this->assertSame('writable/Department/CBA/only-copy.png', $cba['delivery_filepath']);
        $this->assertSame('completed',$cba['status']);
        $this->assertSame(hash_file('sha256',$missing), $this->recoveryDb->table('files')->where('id',$cba['media_id'])->get()->getRowArray()['content_hash']);
        $this->assertSame($created, json_decode(file_get_contents($this->root.'/manifest.json'),true)['created']);
        $again = $this->recovery->plan();
        $this->assertSame(['existing','existing'], array_column($again,'action'));
        $this->assertSame(['file_ids'=>[], 'folder_ids'=>[], 'assignment_ids'=>[]], $this->recovery->apply($again,$this->root.'/again.json'));
    }

    public function testRecoverySkipsDeletedRecordsAndSymlinksAndUnknownDepartments(): void
    {
        $path = $this->photo('Department/CBA/deleted.png');
        $this->recoveryDb->table('files')->insert(['filepath'=>'writable/Department/CBA/deleted.png','content_hash'=>hash_file('sha256',$path),'is_deleted'=>1]);
        symlink($path, $this->root.'/Department/CCS/linked.png');
        mkdir($this->root.'/Department/Unknown');
        copy($path, $this->root.'/Department/Unknown/unassigned.png');
        $plan = $this->recovery->plan();
        $this->assertCount(1,$plan);
        $this->assertSame('skip',$plan[0]['action']);
        $this->recovery->apply($plan,$this->root.'/manifest.json');
        $this->assertSame(0,$this->recoveryDb->table('media_assignments')->countAllResults());
        $this->assertSame(1,(int)$this->recoveryDb->table('files')->get()->getRowArray()['is_deleted']);
    }

    public function testChangedFileRollsBackAllRecoveryInserts(): void
    {
        $this->photo('Department/CCS/Event/one.png');
        $this->photo('Department/CBA/two.png',255);
        $plan = $this->recovery->plan();
        $last = $plan[count($plan)-1];
        $physical = $this->root . '/' . substr($last['path'],strlen('writable/'));
        file_put_contents($physical,'changed after planning');
        try {
            $this->recovery->apply($plan,$this->root.'/manifest.json');
            $this->fail('Expected changed image to abort recovery');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('changed',$e->getMessage());
        }
        foreach (['files','folder','media_assignments'] as $table) $this->assertSame(0,$this->recoveryDb->table($table)->countAllResults());
    }

    protected function tearDown(): void
    {
        $this->recoveryDb->close();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) { if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname()); else unlink($item->getPathname()); }
        rmdir($this->root);
        parent::tearDown();
    }
}
