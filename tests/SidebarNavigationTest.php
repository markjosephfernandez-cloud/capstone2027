<?php

use App\Controllers\Web\AddonsController;
use App\Controllers\Web\ServerLibraryController;
use App\Controllers\Web\WorkspaceController;
use CodeIgniter\Events\Events;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

final class SidebarNavigationTest extends CIUnitTestCase
{
    public function testReadOnlySidebarPagesReleaseSessionBeforeDatabaseWork(): void
    {
        $db = db_connect();
        $this->assertSame('SQLite3', $db->DBDriver);
        $this->assertSame(':memory:', $db->database);
        $previousPrefix = $db->getPrefix();
        $db->setPrefix('');
        $schemas = [
            'files' => 'id folder_id is_deleted display_title filename detected_objects classification emotion gesture tags dateupload filetype mime_type size',
            'folder' => 'id name',
            'ai_event_detections' => 'media_id event_name confidence_score needs_review detector_version evidence_json reviewed_at',
            'user' => 'employee_id name role',
            'passports' => 'media_id uploaded_by updated_at needs_review',
            'server_recent' => 'employee_id media_id viewed_at',
            'server_media' => 'id path source_key is_deleted',
            'audit_logs' => 'id created_at user_id module action description target_type target_id',
        ];
        $forge = \Config\Database::forge($db);
        foreach ($schemas as $table => $columns) {
            $forge->dropTable($table, true);
            $fields = [];
            foreach (explode(' ', $columns) as $column) $fields[$column] = ['type'=>'TEXT', 'null'=>true];
            $forge->addField($fields);
            $forge->createTable($table);
        }
        $db->resetDataCache();
        $actor = ['isLoggedIn'=>true, 'employee_id'=>0, 'role'=>'super_admin'];
        $released = false;
        $queries = 0;
        $session = $this->createMock(Session::class);
        $session->method('get')->willReturnCallback(static fn ($key = null) => $key === null ? $actor : ($actor[$key] ?? null));
        $session->method('close')->willReturnCallback(static function () use (&$released) { $released = true; });
        Services::injectMock('session', $session);
        $listener = function () use (&$released, &$queries) {
            $queries++;
            $this->assertTrue($released, 'Sidebar page queries must not hold the session lock.');
        };
        Events::on('DBQuery', $listener);
        try {
            foreach ([[WorkspaceController::class, 'ifound', 'iFound'], [ServerLibraryController::class, 'recent', 'Recently viewed'], [AddonsController::class, 'passports', 'Metadata Passports'], [AddonsController::class, 'auditLogs', 'Audit Logs']] as [$class, $method, $title]) {
                $released = false;
                $request = new IncomingRequest(config('App'), new URI('http://example.com/'), null, new UserAgent());
                $request->setGlobal('get', []);
                $controller = new $class();
                $controller->initController($request, new Response(config('App')), service('logger'));
                $html = $controller->$method();
                $this->assertIsString($html);
                $this->assertStringContainsString($title, $html);
                $this->assertTrue($released);
            }
            $this->assertGreaterThan(0, $queries);
        } finally {
            Events::removeListener('DBQuery', $listener);
            Services::resetSingle('session');
            foreach (array_keys($schemas) as $table) $forge->dropTable($table, true);
            $db->resetDataCache();
            $db->setPrefix($previousPrefix);
        }
    }

    public function testUnauthorizedSidebarPagesStillRedirect(): void
    {
        $actor = ['isLoggedIn'=>true, 'role'=>'guest'];
        $session = $this->createMock(Session::class);
        $session->method('get')->willReturnCallback(static fn ($key = null) => $key === null ? $actor : ($actor[$key] ?? null));
        $session->expects($this->never())->method('close');
        Services::injectMock('session', $session);
        try {
            foreach ([[WorkspaceController::class, 'ifound'], [AddonsController::class, 'passports'], [AddonsController::class, 'auditLogs']] as [$class, $method]) {
                $this->assertInstanceOf(\CodeIgniter\HTTP\RedirectResponse::class, (new $class())->$method());
            }
        } finally { Services::resetSingle('session'); }
    }
}
