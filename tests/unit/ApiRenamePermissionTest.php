<?php

use App\Controllers\Api\FileController;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Test\CIUnitTestCase;

final class ApiRenamePermissionTest extends CIUnitTestCase
{
    public function testSuperAdminCanReachRenameValidationButDepartmentCannot(): void
    {
        $db = db_connect();
        $this->assertSame('SQLite3', $db->DBDriver);
        $this->assertSame(':memory:', $db->database);
        $table = $db->prefixTable('files');
        $db->query('DROP TABLE IF EXISTS '.$table);
        $db->query('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY, is_deleted INTEGER)');
        $db->table('files')->insert(['id'=>1,'is_deleted'=>0]);
        try {
            foreach (['super_admin'=>422,'admin'=>422,'staff'=>422,'department'=>403,'writer'=>403] as $role=>$expected) {
                $controller = new class extends FileController {
                    public string $testRole;
                    protected function actor(): array { return ['role'=>$this->testRole]; }
                };
                $controller->testRole = $role;
                $request = new IncomingRequest(config('App'),new URI('http://example.com/'), '{}',new UserAgent());
                $request->setHeader('Content-Type','application/json');
                $controller->initController($request,new Response(config('App')),service('logger'));
                $this->assertSame($expected,$controller->rename(1)->getStatusCode(),$role);
            }
        } finally {
            $db->query('DROP TABLE '.$table);
        }
    }
}
