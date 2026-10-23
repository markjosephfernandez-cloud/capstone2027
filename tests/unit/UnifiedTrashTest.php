<?php

use App\Controllers\Web\WorkspaceController;
use App\Services\ServerLibraryEditor;
use App\Services\ServerLibraryService;
use App\Services\StorageService;
use App\Services\TrashService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Config\Storage;

final class UnifiedTrashTest extends CIUnitTestCase
{
    private string $root;
    private string $prefix;
    private ServerLibraryEditor $editor;
    private TrashService $trash;
    private array $actor = ['isLoggedIn'=>true,'employee_id'=>7,'role'=>'admin'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $this->assertSame(':memory:', $db->database);
        $this->prefix = $db->DBPrefix;
        $db->setPrefix('');
        foreach (['files','folder','trashfiles','user'] as $table) $db->query('DROP TABLE IF EXISTS ' . $table);
        $db->query('CREATE TABLE files (id INTEGER PRIMARY KEY, filename TEXT, display_title TEXT, filepath TEXT, filetype TEXT, size INTEGER, dateupload TEXT, last_action TEXT, last_action_at TEXT, is_deleted INTEGER, folder_id INTEGER)');
        $db->query('CREATE TABLE folder (id INTEGER PRIMARY KEY, name TEXT, description TEXT, path TEXT, is_deleted INTEGER, deleted_at TEXT, deleted_by INTEGER, deletion_reason TEXT, trash_root_id INTEGER)');
        $db->query('CREATE TABLE trashfiles (id INTEGER PRIMARY KEY, file_id INTEGER, original_filepath TEXT, folder_root_id INTEGER, deleted_at TEXT, deleted_by INTEGER, reason TEXT)');
        $db->query('CREATE TABLE user (employee_id INTEGER, name TEXT, role TEXT)');
        $db->table('user')->insert(['employee_id'=>7, 'name'=>'Creative User']);
        $db->resetDataCache();
        $this->root = sys_get_temp_dir() . '/unified-trash-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/share/Events', 0775, true);
        $config = new class($this->root) extends Storage {
            public function __construct(private string $testRoot) { parent::__construct(); }
            public function scanSourceRoot(): string { return $this->testRoot . '/share'; }
            public function root(): string { return $this->testRoot . '/local'; }
            public function departmentRoot(): string { return $this->testRoot . '/departments'; }
            public function legacyRoots(): array { return []; }
        };
        $this->editor = new ServerLibraryEditor(new ServerLibraryService($config), $config);
        Services::injectMock('storage', new StorageService($config));
        Services::injectMock('audit', $this->createMock(\App\Services\AuditService::class));
        $session = $this->createMock(Session::class);
        $session->method('get')->willReturnCallback(fn ($key=null) => $key === null ? $this->actor : ($this->actor[$key] ?? null));
        Services::injectMock('session', $session);
        $this->trash = new class($db) extends TrashService {
            // MySQL-only expiry SQL is covered separately; do not expire fixtures.
            public function purgeExpiredTrash(array $actor, ?IncomingRequest $request = null, int $limit = 500): int { return 0; }
        };
    }

    protected function tearDown(): void
    {
        $db = db_connect();
        foreach (['files','folder','trashfiles','user'] as $table) $db->query('DROP TABLE IF EXISTS ' . $table);
        $db->setPrefix($this->prefix); $db->resetDataCache();
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->root);
        parent::tearDown();
    }

    private function call(string $method, array $query = [], array $post = [], array $arguments = [])
    {
        $controller = new class($this->editor, $this->trash) extends WorkspaceController {
            public function __construct(private ServerLibraryEditor $editor, private TrashService $trash) {}
            protected function serverTrashEditor(): ServerLibraryEditor { return $this->editor; }
            protected function trashService(): TrashService { return $this->trash; }
        };
        $request = new IncomingRequest(config('App'), new URI('http://example.com/'), null, new UserAgent());
        $request->setGlobal('get', $query); $request->setGlobal('post', $post);
        $controller->initController($request, new Response(config('App')), service('logger'));
        return $controller->$method(...$arguments);
    }

    private function serverFile(string $name): string
    {
        file_put_contents($this->root . '/share/Events/' . $name, 'server original');
        $this->editor->trash(['Events/' . $name], 7);
        return array_key_last($this->editor->state()['trash']);
    }

    private function localFile(int $id): void
    {
        $path = service('storage')->root() . '/local-' . $id . '.jpg';
        file_put_contents($path, 'local original');
        $file = ['id'=>$id,'filename'=>'local-'.$id.'.jpg','display_title'=>'Local '.$id,'filepath'=>service('storage')->databasePath($path),'filetype'=>'jpg','size'=>14,'dateupload'=>gmdate('Y-m-d H:i:s'),'is_deleted'=>0];
        db_connect()->table('files')->insert($file);
        $this->trash->moveToTrash([$file], $this->actor, 'Local test');
    }

    public function testPreviewReadsActualTrashFilesAndDeletedFolderChildren(): void
    {
        $source = $this->root . '/share/Events/original.png';
        $image = imagecreatetruecolor(64, 48);
        imagepng($image, $source);
        imagedestroy($image);
        $originalHash = hash_file('sha256', $source);
        $this->editor->rename('Events/original.png', 'renamed.png');
        $this->editor->trash(['Events'], 7);
        $id = array_key_last($this->editor->state()['trash']);
        $before = $this->editor->state();
        $response = $this->call('serverTrashPreview', ['thumbnail'=>'1', 'child'=>'renamed.png'], [], [$id]);
        $this->assertSame('image/jpeg', getimagesizefromstring($response->getBody())['mime']);
        $this->assertSame($originalHash, hash_file('sha256', $this->editor->previewPath($id, 'renamed.png')));
        $this->assertSame($before, $this->editor->state());
        $html = $this->call('serverTrashFolder', [], [], [$id]);
        $this->assertStringContainsString('renamed.png', $html);
        $this->assertStringContainsString('thumbnail=1', $html);
        $this->assertSame(404, $this->call('serverTrashPreview', ['child'=>'../escape'], [], [$id])->getStatusCode());

        $this->localFile(1);
        $file = db_connect()->table('files')->where('id', 1)->get()->getRowArray();
        $physical = service('storage')->resolveDatabaseFile($file['filepath']);
        $image = imagecreatetruecolor(64, 48);
        imagejpeg($image, $physical);
        imagedestroy($image);
        // A file belonging to a deleted folder still resolves via its stored Trash path.
        db_connect()->table('trashfiles')->where('file_id', 1)->update(['folder_root_id'=>99]);
        $hash = hash_file('sha256', $physical);
        $response = $this->call('trashPreview', ['thumbnail'=>'1'], [], [1]);
        $this->assertSame('image/jpeg', getimagesizefromstring($response->getBody())['mime']);
        $this->assertSame($hash, hash_file('sha256', $physical));
        $this->assertSame(1, (int) db_connect()->table('files')->where('id', 1)->get()->getRowArray()['is_deleted']);
    }

    public function testServerTrashVideoProducesPrivatePosterWithoutChangingOriginal(): void
    {
        $ffmpeg = null;
        foreach (['/opt/homebrew/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg'] as $binary) {
            if (is_executable($binary)) { $ffmpeg = $binary; break; }
        }
        if ($ffmpeg === null) $this->markTestSkipped('FFmpeg is required.');
        $path = $this->root . '/share/Events/clip.mp4';
        $process = proc_open([$ffmpeg, '-nostdin', '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=64x48:r=10', '-t', '0.3', '-c:v', 'mpeg4', $path], [0=>['pipe','r'], 1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        fclose($pipes[0]);
        stream_get_contents($pipes[1]); fclose($pipes[1]);
        $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $error);
        $hash = hash_file('sha256', $path);
        $this->editor->trash(['Events/clip.mp4'], 7);
        $id = array_key_last($this->editor->state()['trash']);
        $response = $this->call('serverTrashPreview', ['thumbnail'=>'1'], [], [$id]);
        $this->assertSame('image/jpeg', getimagesizefromstring($response->getBody())['mime']);
        $this->assertSame('private, no-cache, must-revalidate', $response->getHeaderLine('Cache-Control'));
        $this->assertSame($hash, hash_file('sha256', $this->editor->previewPath($id)));
    }

    public function testBothLibrariesAppearInTheSameSearchablePaginatedGrid(): void
    {
        for ($id=1; $id<=19; $id++) $this->localFile($id);
        $serverId = $this->serverFile('server-photo.png');
        $this->serverFile('server-video.mp4');
        $html = $this->call('trash');
        $this->assertSame(20, substr_count($html, 'data-trash-item>'));
        $this->assertStringContainsString('Local 19', $html);
        $this->assertStringContainsString('data-trash-type="server"', $html);
        $this->assertStringContainsString('page=2', $html);
        $next = $this->call('trash', ['page'=>2]);
        $this->assertSame(1, substr_count($next, 'data-trash-item>'));
        $filtered = $this->call('trash', ['q'=>'server-photo', 'type'=>'png']);
        $this->assertStringContainsString('data-trash-id="'.$serverId.'"', $filtered);
        $this->assertStringNotContainsString('Local 19', $filtered);
        $this->assertStringNotContainsString('server-video.mp4', $filtered);
        $this->assertStringContainsString('Creative User', $filtered);
        $this->assertStringContainsString('Retention: 30 days', $filtered);
        $this->assertStringNotContainsString('No automatic expiry', $filtered);
    }

    public function testAuditScopesActivityAndFilterOptionsToActorExceptSuperAdmin(): void
    {
        $db = db_connect();
        $forge = \Config\Database::forge();
        $fields = ['id'=>['type'=>'INTEGER', 'auto_increment'=>true]];
        foreach (['user_id', 'user_name', 'user_role', 'department', 'module', 'action', 'target_type', 'target_id', 'description', 'ip_address', 'created_at'] as $field) {
            $fields[$field] = ['type'=>'TEXT', 'null'=>true];
        }
        $forge->addField($fields);
        $forge->addKey('id', true);
        $forge->createTable('audit_logs');
        $db->resetDataCache();
        try {
            $this->actor = ['isLoggedIn'=>true, 'employee_id'=>7, 'name'=>'Creative User', 'role'=>'staff'];
            $audit = new \App\Services\AuditService($db);
            Services::injectMock('audit', $audit);
            $request = new IncomingRequest(config('App'), new URI('http://example.com/'), null, new UserAgent());
            $request->setGlobal('get', []);
            $controller = new class extends \App\Controllers\Web\ServerLibraryController {
                public function recordTrashForTest(): void { $this->recordEdit('trash', ['photo-audit.jpg']); }
            };
            $controller->initController($request, new Response(config('App')), service('logger'));
            $controller->recordTrashForTest();
            $row = $db->table('audit_logs')->get()->getRowArray();
            $this->assertSame('7', $row['user_id']);
            $this->assertSame('staff', $row['user_role']);
            $this->assertSame('delete', $row['module']);
            $this->assertSame('SERVER_LIBRARY_TRASH', $row['action']);
            $db->table('audit_logs')->where('id', $row['id'])->update(['created_at'=>'2026-10-02 09:00:00']);
            $audit->record($this->actor, $request, 'file', 'SERVER_LIBRARY_TRASH', 'server_library', null, 'historical-photo.jpg');
            $audit->record($this->actor, $request, 'admin', 'PRIVATE_ADMIN_ACTION', 'system', null, 'restricted-admin-entry');
            $audit->record(['employee_id'=>8, 'name'=>'Other Staff', 'role'=>'staff'], $request, 'other_user_module', 'OTHER_USER_ACTION', 'system', null, 'other-user-private-entry');
            $audit->record(['employee_id'=>0, 'role'=>'system'], null, 'delete', 'AUTO_SERVER_TRASH_PURGE', 'server_library', null, 'expired-photo.jpg');
            $dashboard = new class extends \App\Controllers\Web\DashboardController {
                public function activityFor(array $actor): array { return $this->recentActivity($actor); }
            };
            foreach (['staff', 'admin', 'writer', 'department'] as $role) {
                $recent = array_column($dashboard->activityFor(['employee_id'=>7, 'role'=>$role]), 'description');
                $this->assertCount(3, $recent);
                $this->assertContains('historical-photo.jpg', $recent);
                $this->assertNotContains('other-user-private-entry', $recent);
                $this->assertNotContains('expired-photo.jpg', $recent);
            }
            $this->assertArrayHasKey('id', $dashboard->activityFor($this->actor)[0]);
            $allRecent = array_column($dashboard->activityFor(['employee_id'=>7, 'role'=>'super_admin']), 'description');
            $this->assertCount(5, $allRecent);
            $this->assertContains('other-user-private-entry', $allRecent);
            $this->assertContains('expired-photo.jpg', $allRecent);
            $this->assertSame([], $dashboard->activityFor(['role'=>'admin']));
            $this->assertSame([], $dashboard->activityFor(['employee_id'=>0, 'role'=>'staff']));
            $addons = new \App\Controllers\Web\AddonsController();
            $addons->initController($request, new Response(config('App')), service('logger'));
            $html = $addons->auditLogs();
            $this->assertStringContainsString('photo-audit.jpg', $html);
            $this->assertStringContainsString('Oct 02, 2026 05:00 PM', $html);
            $this->assertStringContainsString('Timestamp (PHT)', $html);
            $this->assertStringContainsString('historical-photo.jpg', $html);
            $this->assertStringNotContainsString('expired-photo.jpg', $html);
            $this->assertStringContainsString('restricted-admin-entry', $html);
            $this->assertStringNotContainsString('other-user-private-entry', $html);
            $this->assertStringNotContainsString('other_user_module', $html);
            $this->assertStringNotContainsString('OTHER_USER_ACTION', $html);
            $this->assertStringNotContainsString('id="auditUser"', $html);
            foreach (['staff', 'admin'] as $role) {
                $this->actor['role'] = $role;
                $request->setGlobal('get', ['user'=>'8', 'q'=>'other-user-private-entry']);
                $this->assertStringContainsString('No audit logs match your filters.', $addons->auditLogs());
                $request->setGlobal('get', ['module'=>'other_user_module', 'action'=>'OTHER_USER_ACTION']);
                $this->assertStringContainsString('No audit logs match your filters.', $addons->auditLogs());
            }
            $this->actor['role'] = 'super_admin';
            $request->setGlobal('get', []);
            $all = $addons->auditLogs();
            $this->assertStringNotContainsString('id="auditSearch"', $all);
            $this->assertStringContainsString('search-suggestions/audit-users', $all);
            foreach (['photo-audit.jpg','historical-photo.jpg','expired-photo.jpg','other-user-private-entry','other_user_module','OTHER_USER_ACTION','id="auditUser"'] as $visible) {
                $this->assertStringContainsString($visible, $all);
            }
            $request->setGlobal('get', ['user'=>'8']);
            $otherUser = $addons->auditLogs();
            $this->assertStringContainsString('other-user-private-entry', $otherUser);
            $this->assertStringNotContainsString('historical-photo.jpg', $otherUser);
            $this->actor['role'] = 'staff';
            $request->setGlobal('get', ['module'=>'delete']);
            $filtered = $addons->auditLogs();
            $this->assertStringContainsString('photo-audit.jpg', $filtered);
            $this->assertStringNotContainsString('historical-photo.jpg', $filtered);
            // Exact links must work beyond the normal 200-entry window.
            for ($i = 0; $i < 201; $i++) {
                $audit->record($this->actor, $request, 'auth', 'LOGIN', 'system', null, 'newer-entry-' . $i);
            }
            $request->setGlobal('get', ['log'=>(string) $row['id']]);
            $exact = $addons->auditLogs();
            $this->assertStringContainsString('photo-audit.jpg', $exact);
            $this->assertStringContainsString('id="audit-log-' . $row['id'] . '"', $exact);
            $this->assertStringContainsString('<details class="audit-details" open>', $exact);
            $this->assertStringContainsString('View all audit logs', $exact);
            $this->assertStringNotContainsString('newer-entry-', $exact);
            $otherId = $db->table('audit_logs')->where('user_id', 8)->get()->getRowArray()['id'];
            $request->setGlobal('get', ['log'=>(string) $otherId]);
            $this->assertStringNotContainsString('other-user-private-entry', $addons->auditLogs());
            $this->assertStringContainsString('No audit logs match your filters.', $addons->auditLogs());
            $this->actor['role'] = 'super_admin';
            $this->assertStringContainsString('other-user-private-entry', $addons->auditLogs());
            $this->actor['role'] = 'staff';
            foreach (['99999999', 'invalid', '-1'] as $missingId) {
                $request->setGlobal('get', ['log'=>$missingId]);
                $this->assertStringContainsString('No audit logs match your filters.', $addons->auditLogs());
            }
            $this->actor['employee_id'] = 0;
            $this->assertSame(403, $addons->auditLogs()->getStatusCode());
        } finally {
            $forge->dropTable('audit_logs');
            $db->resetDataCache();
        }
    }

    public function testMixedRestoreUsesOriginalStorageForEachItemAndDeletionRespectsRoles(): void
    {
        $this->localFile(1);
        $id = $this->serverFile('restore.jpg');
        $response = $this->call('bulkRestoreTrash', [], ['file_ids'=>[1], 'server_ids'=>[$id]]);
        $this->assertStringEndsWith('/workspace/trash', $response->getHeaderLine('Location'));
        $this->assertSame('local original', file_get_contents($this->root . '/local/local-1.jpg'));
        $this->assertSame('server original', file_get_contents($this->root . '/share/Events/restore.jpg'));
        $this->assertSame([], $this->editor->state()['trash']);
        $id = $this->serverFile('remove.jpg');
        $this->actor['role'] = 'staff';
        $this->call('bulkDeleteTrash', [], ['server_ids'=>[$id]]);
        $this->assertArrayHasKey($id, $this->editor->state()['trash']);
        $this->actor['role'] = 'admin';
        $this->call('bulkDeleteTrash', [], ['server_ids'=>[$id]]);
        $this->assertSame([], $this->editor->state()['trash']);
        $this->assertFileDoesNotExist($this->root . '/share/.greyhound-library/trash-' . $id);
    }

    public function testDisconnectedServerDoesNotHideOrBlockLocalTrash(): void
    {
        $this->localFile(1);
        $id = $this->serverFile('offline.jpg');
        rename($this->root . '/share', $this->root . '/offline');
        try {
            $html = $this->call('trash');
            $this->assertStringContainsString('Local 1', $html);
            $this->assertStringContainsString('Server items are unavailable', $html);
            $this->call('bulkRestoreTrash', [], ['file_ids'=>[1], 'server_ids'=>[$id]]);
            $this->assertFileExists($this->root . '/local/local-1.jpg');
        } finally { rename($this->root . '/offline', $this->root . '/share'); }
        $this->assertArrayHasKey($id, $this->editor->state()['trash']);
    }
}
