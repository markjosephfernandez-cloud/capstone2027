<?php

use App\Controllers\Api\FolderController;
use App\Controllers\Web\WorkspaceController;
use App\Libraries\AuthContext;
use App\Models\FolderModel;
use App\Services\AuditService;
use App\Services\StorageService;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use Config\Storage;

final class FolderNameReuseTest extends CIUnitTestCase
{
    private string $temporaryRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryRoot = sys_get_temp_dir() . '/greyhound-folder-reuse-' . bin2hex(random_bytes(6));
        $config = new class extends Storage {
            public string $temporaryRoot;
            public function root(): string { return $this->temporaryRoot; }
        };
        $config->temporaryRoot = $this->temporaryRoot;
        Services::injectMock('storage', new StorageService($config));
        Services::injectMock('audit', $this->createMock(AuditService::class));
        $actor = ['isLoggedIn' => true, 'role' => 'admin', 'employee_id' => 1];
        $session = $this->createMock(Session::class);
        $session->method('get')->willReturnCallback(static fn ($key = null) => $key === null ? $actor : ($actor[$key] ?? null));
        Services::injectMock('session', $session);
        $context = new AuthContext();
        $context->setActor($actor);
        Services::injectMock('authContext', $context);
        $db = db_connect();
        $table = $db->prefixTable('folder');
        $db->query('DROP TABLE IF EXISTS ' . $table);
        $db->query('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, path TEXT, parent_id INTEGER NULL, description TEXT, created_at TEXT, is_deleted INTEGER DEFAULT 0)');
        (new FolderModel())->insert(['name' => 'Events', 'path' => 'Events', 'is_deleted' => 1]);
        service('storage')->directory('Events/Nested', true);
        file_put_contents($this->temporaryRoot . '/Events/Nested/legacy.jpg', 'original');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->temporaryRoot)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->temporaryRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $entry) {
                $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            }
            rmdir($this->temporaryRoot);
        }
        parent::tearDown();
    }

    private function controller(string $class, array $input = [])
    {
        $request = new IncomingRequest(config('App'), new URI('http://example.com/'), json_encode($input), new UserAgent());
        $request->setGlobal('post', $input);
        $request->setGlobal('get', []);
        $controller = new $class();
        $controller->initController($request, new Response(config('App')), service('logger'));
        return $controller;
    }

    public function testWebCanReuseDeletedNameButRejectsAnotherActiveDuplicate(): void
    {
        $response = $this->controller(WorkspaceController::class, ['name' => 'Events'])->createFolder();
        $folder = (new FolderModel())->where('is_deleted', 0)->first();
        $this->assertNotNull($folder);
        $this->assertSame('Events', $folder['name']);
        $this->assertNotSame('Events', $folder['path']);
        $this->assertSame(site_url('workspace/file-management'), $response->getHeaderLine('Location'));
        $this->assertDirectoryExists($this->temporaryRoot . '/' . $folder['path']);
        $this->assertSame('original', file_get_contents($this->temporaryRoot . '/Events/Nested/legacy.jpg'));
        $this->controller(WorkspaceController::class, ['name' => 'Events'])->createFolder();
        $this->assertSame(1, (new FolderModel())->where('is_deleted', 0)->countAllResults());
    }

    public function testApiReusesNameAndListsDisplayNameAndSupportsChildren(): void
    {
        $response = $this->controller(FolderController::class, ['name' => 'Events'])->create();
        $this->assertSame(201, $response->getStatusCode());
        $folder = json_decode($response->getBody(), true)['data'];
        $this->assertSame('Events', $folder['name']);
        $this->assertNotSame('Events', $folder['path']);
        $listing = $this->controller(FolderController::class)->index();
        $items = json_decode($listing->getBody(), true)['data'];
        $this->assertCount(1, $items);
        $this->assertSame('Events', $items[0]['name']);
        $this->assertSame(409, $this->controller(FolderController::class, ['name' => 'Events'])->create()->getStatusCode());
        $childResponse = $this->controller(FolderController::class, ['name' => 'Photos', 'parent_path' => $folder['path']])->create();
        $this->assertSame(201, $childResponse->getStatusCode());
        $child = json_decode($childResponse->getBody(), true)['data'];
        $this->assertSame((int) $folder['id'], (int) $child['parent_id']);
        $this->assertSame($folder['path'] . '/Photos', $child['path']);
    }

    public function testWebCanRenameAFolderToADeletedName(): void
    {
        $db = db_connect();
        $table = $db->prefixTable('files');
        $db->query('DROP TABLE IF EXISTS ' . $table);
        $db->query('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY, filepath TEXT)');
        $id = (new FolderModel())->insert(['name' => 'Other', 'path' => 'Other'], true);
        service('storage')->directory('Other', true);
        file_put_contents($this->temporaryRoot . '/Other/new.jpg', 'new media');
        $db->table('files')->insert(['id' => 1, 'filepath' => 'writable/uploads/Other/new.jpg']);

        $this->controller(WorkspaceController::class, ['name' => 'Events'])->renameFolder((int) $id);
        $folder = (new FolderModel())->find($id);
        $this->assertSame('Events', $folder['name']);
        $this->assertNotSame('Events', $folder['path']);
        $this->assertSame('new media', file_get_contents($this->temporaryRoot . '/' . $folder['path'] . '/new.jpg'));
        $this->assertSame('original', file_get_contents($this->temporaryRoot . '/Events/Nested/legacy.jpg'));
        $this->assertSame('writable/uploads/' . $folder['path'] . '/new.jpg', $db->table('files')->where('id', 1)->get()->getRowArray()['filepath']);
    }
}
