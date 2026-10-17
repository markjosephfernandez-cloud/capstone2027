<?php

use App\Services\PassportService;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class PassportExifTest extends CIUnitTestCase
{
    public function testDateTimeOriginalHasPriority(): void
    {
        $method = new ReflectionMethod(PassportService::class, 'dateTakenFromExif');
        $date = $method->invoke(new PassportService(), [
            'EXIF' => [
                'DateTimeOriginal' => '2026:03:24 16:00:06',
                'DateTimeDigitized' => '2026:03:25 10:00:00',
            ],
            'IFD0' => ['DateTime' => '2026:03:26 10:00:00'],
        ]);

        $this->assertSame('2026-03-24 16:00:06', $date);
    }

    public function testDateTimeDigitizedAndIfdDateAreFallbacks(): void
    {
        $method = new ReflectionMethod(PassportService::class, 'dateTakenFromExif');
        $service = new PassportService();

        $this->assertSame('2025-12-31 23:59:58', $method->invoke($service, [
            'EXIF' => ['DateTimeDigitized' => '2025:12:31 23:59:58'],
        ]));
        $this->assertSame('2024-02-29 08:15:10', $method->invoke($service, [
            'IFD0' => ['DateTime' => '2024:02:29 08:15:10'],
        ]));
    }

    public function testMissingOrInvalidExifDateRemainsNull(): void
    {
        $method = new ReflectionMethod(PassportService::class, 'dateTakenFromExif');
        $service = new PassportService();

        $this->assertNull($method->invoke($service, []));
        $this->assertNull($method->invoke($service, ['EXIF' => ['DateTimeOriginal' => '0000:00:00 00:00:00']]));
        $this->assertNull($method->invoke($service, ['EXIF' => ['DateTimeOriginal' => '2026:02:30 12:00:00']]));
    }
    public function testSelectedMetadataScanPreservesExistingPassports(): void
    {
        $db = db_connect();
        $this->assertSame(':memory:', $db->database);
        $forge = \Config\Database::forge($db);
        $schemas = [
            'files'=>['id','filename','filetype','folder_id','filepath','is_deleted','employee_id'],
            'folder'=>['id','name','parent_id','is_deleted'],
            'passports'=>['id','media_id'],
            'ai_event_detections'=>['id','media_id','event_name','reviewed_at'],
        ];
        $physical = tempnam(sys_get_temp_dir(), 'passport-scan-');
        file_put_contents($physical, 'image fixture');
        try {
            foreach ($schemas as $table=>$columns) {
                $forge->dropTable($table, true);
                foreach ($columns as $column) $forge->addField([$column=>['type'=>in_array($column, ['id','media_id','folder_id','parent_id','is_deleted','employee_id']) ? 'INTEGER' : 'TEXT', 'null'=>true]]);
                $forge->createTable($table);
            }
            $db->table('folder')->insertBatch([['id'=>7,'name'=>'Events','parent_id'=>null,'is_deleted'=>0], ['id'=>8,'name'=>'Graduation','parent_id'=>7,'is_deleted'=>0]]);
            foreach (range(1, 5) as $id) $db->table('files')->insert(['id'=>$id,'filename'=>'photo'.$id.'.jpg','filetype'=>$id===4?'mp4':'jpg','folder_id'=>8,'filepath'=>'selected.jpg','is_deleted'=>$id===5?1:0]);
            $db->table('passports')->insert(['id'=>1,'media_id'=>2]);
            $db->table('ai_event_detections')->insert(['id'=>1,'media_id'=>1,'event_name'=>'Reviewed event','reviewed_at'=>'2026-10-07']);
            $service = $this->getMockBuilder(PassportService::class)->onlyMethods(['generate'])->getMock();
            $service->expects($this->once())->method('generate')->with(
                $this->callback(fn ($file) => (int) $file['id'] === 1),
                ['employee_id'=>7],
                $this->callback(fn ($detection) => $detection['event_name'] === 'Reviewed event' && $detection['reviewed_at'] === '2026-10-07')
            )->willReturn(['id'=>2]);
            $storage = $this->createMock(\App\Services\StorageService::class);
            $storage->method('resolveDatabaseFile')->willReturn($physical);
            \Config\Services::injectMock('storage', $storage);
            $this->assertSame('skipped', $service->generateMissingImage(2, [])['status']);
            $this->assertSame('complete', $service->generateMissingImage(1, ['employee_id'=>7])['status']);
            foreach ([4,5,999] as $id) {
                try { $service->generateMissingImage($id, []); $this->fail('Unavailable or non-image file accepted.'); }
                catch (RuntimeException $error) { $this->assertStringContainsString('unavailable', $error->getMessage()); }
            }
            $this->assertSame(1, $db->table('passports')->countAllResults());
            $this->assertSame('Reviewed event', $db->table('ai_event_detections')->get()->getRowArray()['event_name']);
        } finally {
            unlink($physical);
            foreach (array_keys($schemas) as $table) $forge->dropTable($table, true);
            $db->resetDataCache();
        }
    }

    public function testMetadataScanEndpointsRequireAdministratorAndValidSelection(): void
    {
        foreach ([[[],401], [['isLoggedIn'=>true,'role'=>'department'],403], [['isLoggedIn'=>true,'role'=>'admin','employee_id'=>7],422]] as [$actor,$expected]) {
            $session = $this->createMock(\CodeIgniter\Session\Session::class);
            $session->method('get')->willReturnCallback(fn ($key=null) => $key === null ? $actor : ($actor[$key] ?? null));
            \Config\Services::injectMock('session', $session);
            $request = new \CodeIgniter\HTTP\IncomingRequest(config('App'), new \CodeIgniter\HTTP\URI('http://example.com/'), null, new \CodeIgniter\HTTP\UserAgent());
            $request->setGlobal('post', ['file_path'=>['invalid']]);
            $request->setGlobal('get', ['folder'=>'../invalid']);
            $controller = new \App\Controllers\Web\PassportController();
            $controller->initController($request, new \CodeIgniter\HTTP\Response(config('App')), service('logger'));
            $this->assertSame($expected, $controller->scanImage()->getStatusCode());
            $this->assertSame($expected, $controller->browseScan()->getStatusCode());
        }
    }
    public function testPassportPickerUsesSameServerBrowserAndScansSelectedPath(): void
    {
        $actor = ['isLoggedIn'=>true, 'role'=>'admin', 'employee_id'=>7];
        $session = $this->createMock(\CodeIgniter\Session\Session::class);
        $session->method('get')->willReturnCallback(fn ($key=null) => $key === null ? $actor : ($actor[$key] ?? null));
        \Config\Services::injectMock('session', $session);
        $scanner = $this->createMock(\App\Services\ServerScanService::class);
        $scanner->expects($this->once())->method('browse')->with('Events/Graduation', 'portrait', 2)->willReturn(['ok'=>true, 'items'=>[['path'=>'Events/Graduation/portrait.jpg', 'name'=>'portrait.jpg', 'directory'=>false]], 'page'=>2, 'pages'=>3]);
        $scanner->expects($this->once())->method('passportFile')->with('Events/Graduation/portrait.jpg', 7)->willReturn(42);
        \Config\Services::injectMock('serverScan', $scanner);
        $passports = $this->createMock(PassportService::class);
        $passports->expects($this->once())->method('generateMissingImage')->with(42, $actor)->willReturn(['status'=>'skipped']);
        \Config\Services::injectMock('passports', $passports);
        $request = new \CodeIgniter\HTTP\IncomingRequest(config('App'), new \CodeIgniter\HTTP\URI('http://example.com/'), null, new \CodeIgniter\HTTP\UserAgent());
        $request->setGlobal('get', ['folder'=>'Events/Graduation', 'q'=>'portrait', 'page'=>2]);
        $request->setGlobal('post', ['file_path'=>'Events/Graduation/portrait.jpg']);
        $controller = new \App\Controllers\Web\PassportController();
        $controller->initController($request, new \CodeIgniter\HTTP\Response(config('App')), service('logger'));
        $this->assertSame(200, $controller->browseScan()->getStatusCode());
        $this->assertSame('skipped', json_decode($controller->scanImage()->getBody(), true)['status']);
    }
}
