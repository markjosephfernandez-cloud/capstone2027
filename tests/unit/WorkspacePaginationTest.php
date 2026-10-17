<?php

use App\Controllers\Web\WorkspaceController;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

final class WorkspacePaginationTest extends CIUnitTestCase
{
    private array $actor = ['isLoggedIn'=>true, 'employee_id'=>0, 'role'=>'department', 'department'=>'CCS'];
    private array $tables = ['department_files','department_folders','files','folder','user','media_requests','duplicate_media','passports'];

    protected function setUp(): void
    {
        parent::setUp();
        $db = db_connect();
        $this->assertSame('SQLite3', $db->DBDriver);
        $this->assertSame(':memory:', $db->database);
        foreach ($this->tables as $table) $db->query('DROP TABLE IF EXISTS '.$db->prefixTable($table));
        foreach (['files','department_files'] as $table) {
            $db->query('CREATE TABLE '.$db->prefixTable($table).' (id INTEGER PRIMARY KEY, department TEXT, filename TEXT, display_title TEXT, filepath TEXT, filetype TEXT, mime_type TEXT, size INTEGER, dateupload TEXT, folder_id INTEGER, tags TEXT, description TEXT, classification TEXT, detected_objects TEXT, storage_stage TEXT, distribution_type TEXT, release_status TEXT, is_deleted INTEGER DEFAULT 0)');
            for ($id=1; $id<=48; ++$id) $db->table($table)->insert([
                'id'=>$id, 'department'=>$id<=45?'CCS':'CBA', 'filename'=>"photo-$id.jpg", 'display_title'=>'Campus event',
                'filetype'=>'jpg', 'mime_type'=>'image/jpeg', 'size'=>100, 'dateupload'=>'2026-09-19 00:00:00',
                'folder_id'=>$id<=45?1:2, 'tags'=>'campus', 'description'=>'', 'classification'=>'', 'detected_objects'=>'',
            ]);
        }
        foreach (['folder','department_folders'] as $table) {
            $db->query('CREATE TABLE '.$db->prefixTable($table).' (id INTEGER PRIMARY KEY, department TEXT, name TEXT, path TEXT, description TEXT, parent_id INTEGER, created_at TEXT, is_deleted INTEGER DEFAULT 0)');
            $db->table($table)->insertBatch([
                ['id'=>1,'department'=>'CCS','name'=>'Campus','path'=>'Campus'],
                ['id'=>2,'department'=>'CBA','name'=>'Other','path'=>'Other'],
            ]);
        }
        $db->query('CREATE TABLE '.$db->prefixTable('user').' (department TEXT)');
        $db->query('CREATE TABLE '.$db->prefixTable('media_requests').' (status TEXT, submitted_at TEXT)');
        $db->query('CREATE TABLE '.$db->prefixTable('duplicate_media').' (original_file_id INTEGER, status TEXT)');
        $db->resetDataCache();
        $session=$this->createMock(Session::class);
        $session->method('get')->willReturnCallback(fn($key=null)=>$key===null?$this->actor:($this->actor[$key]??null));
        Services::injectMock('session',$session);
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) db_connect()->query('DROP TABLE IF EXISTS '.db_connect()->prefixTable($table));
        db_connect()->resetDataCache();
        parent::tearDown();
    }

    private function page(array $query): string
    {
        $request=new IncomingRequest(config('App'),new URI('http://example.com/'),null,new UserAgent());
        $request->setGlobal('get',$query);
        $controller=new WorkspaceController();
        $controller->initController($request,new Response(config('App')),service('logger'));
        return $controller->media();
    }

    private function ids(string $html): array
    {
        preg_match_all('~/workspace/file-management/(\d+)/preview~',$html,$matches);
        return array_values(array_unique(array_map('intval',$matches[1])));
    }

    private function suggestions(array $query)
    {
        $request = new IncomingRequest(config('App'), new URI('http://example.com/'), null, new UserAgent());
        $request->setGlobal('get', $query);
        $controller = new WorkspaceController();
        $controller->initController($request, new Response(config('App')), service('logger'));
        return $controller->mediaSuggestions();
    }

    public function testSuggestionsSearchTheLibraryAndRespectDepartmentAccessAndDeletedFiles(): void
    {
        $db = db_connect();
        $db->table('department_files')->where('id', 1)->update(['tags' => 'Graduation, Campus', 'display_title' => 'Graduation day']);
        $db->table('department_files')->where('id', 46)->update(['tags' => 'Graduation private']);
        $db->table('department_files')->where('id', 2)->update(['display_title' => 'Graduation deleted', 'is_deleted' => 1]);
        $db->table('department_folders')->where('id', 2)->update(['name' => 'Graduation private folder']);
        $response = $this->suggestions(['q' => 'grad', 'folder' => 999]);
        $values = array_column(json_decode($response->getBody(), true)['suggestions'], 'value');
        $this->assertSame(['Graduation', 'Graduation day'], $values);
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $values = array_column(json_decode($this->suggestions(['q' => 'camp'])->getBody(), true)['suggestions'], 'value');
        $this->assertSame(['Campus', 'Campus event'], $values);
        $this->assertCount(8, json_decode($this->suggestions(['q' => 'photo'])->getBody(), true)['suggestions']);
        $this->assertSame([], json_decode($this->suggestions(['q' => 'p'])->getBody(), true)['suggestions']);
        $this->assertSame([], json_decode($this->suggestions(['q' => ''])->getBody(), true)['suggestions']);
    }

    public function testAdminSuggestionsHonorFileFiltersAndIncludePassportEventsAndDates(): void
    {
        $this->actor['role'] = 'admin';
        $db = db_connect();
        $db->table('files')->where('id', 1)->update(['filename' => 'Festival.mp4', 'filetype' => 'mp4', 'storage_stage' => 'processed']);
        $db->table('files')->where('id', 2)->update(['filename' => 'Festival.jpg', 'storage_stage' => 'raw']);
        $db->query('CREATE TABLE '.$db->prefixTable('passports').' (media_id INTEGER, event_name TEXT, date_taken TEXT)');
        $db->table('passports')->insert(['media_id' => 1, 'event_name' => 'Festival night', 'date_taken' => '2026-09-20']);
        $values = array_column(json_decode($this->suggestions(['q' => 'festival', 'type' => 'mp4'])->getBody(), true)['suggestions'], 'value');
        $this->assertSame(['Festival.mp4', 'Festival night'], $values);
        $values = array_column(json_decode($this->suggestions(['q' => 'festival', 'stage' => 'raw'])->getBody(), true)['suggestions'], 'value');
        $this->assertSame(['Festival.jpg'], $values);
        $this->assertSame('2026-09-20', json_decode($this->suggestions(['q' => '2026-09'])->getBody(), true)['suggestions'][0]['value']);
        $this->actor['isLoggedIn'] = false;
        $this->assertSame(302, $this->suggestions(['q' => 'festival'])->getStatusCode());
    }

    public function testSuggestionsImmediatelyUseReviewedClassificationAndKeepOriginalDates(): void
    {
        $this->actor['role'] = 'admin';
        $db = db_connect();
        $db->query('CREATE TABLE '.$db->prefixTable('passports').' (media_id INTEGER, event_name TEXT, date_taken TEXT)');
        $db->resetDataCache();
        $db->table('passports')->insert(['media_id' => 1, 'event_name' => 'Dal-uy Events', 'date_taken' => '2024-01-17']);
        $values = fn (string $query): array => array_column(json_decode($this->suggestions(['q' => $query])->getBody(), true)['suggestions'], 'value');
        $this->assertSame(['Dal-uy Events'], $values('dal'));

        $db->table('files')->where('id', 1)->update(['classification' => 'EXPO Activities', 'tags' => 'EXPO Activities, expo activities']);
        $this->assertSame([], $values('dal'));
        $this->assertSame(['EXPO Activities'], $values('expo'));
        $this->assertSame(['2024-01-17'], $values('2024-01'));
        $db->table('files')->where('id', 1)->update(['is_deleted' => 1]);
        $this->assertSame([], $values('expo'));
        $this->assertSame([], $values('2024-01'));
    }

    public function testSuggestionsRespectDistributionAndPublishedFilters(): void
    {
        $this->actor['role'] = 'admin';
        $db = db_connect();
        $db->table('files')->where('id', 1)->update(['display_title' => 'Featured mobile photo', 'distribution_type' => 'mobile_app', 'release_status' => 'published']);
        $db->table('files')->where('id', 2)->update(['display_title' => 'Featured department photo', 'distribution_type' => 'department']);
        $values = fn (array $filters): array => array_column(json_decode($this->suggestions(['q' => 'featured'] + $filters)->getBody(), true)['suggestions'], 'value');
        $this->assertSame(['Featured mobile photo'], $values(['stage' => 'published']));
        $this->assertSame(['Featured department photo'], $values(['distribution' => 'department']));
        $this->assertSame([], $values(['stage' => 'published', 'distribution' => 'department']));
    }

    public function testDepartmentPagesAreStableAndExcludeOtherDepartments(): void
    {
        $ids=[];
        foreach ([1=>20,2=>20,3=>5] as $page=>$count) {
            $html=$this->page(['folder'=>1,'type'=>'jpg','page'=>$page]);
            $pageIds=$this->ids($html);
            $this->assertCount($count,$pageIds);
            $ids=array_merge($ids,$pageIds);
            $this->assertStringContainsString('aria-label="File Management pages"',$html);
            $this->assertStringContainsString('type=jpg&amp;folder=1&amp;page=', $html);
        }
        $this->assertSame(range(45,1),$ids);
        $this->assertSame(range(25,6),$this->ids($this->page(['q'=>'Campus','page'=>2])));
    }

    public function testSearchUsesUpdatedClassificationInsteadOfOldPassportEvent(): void
    {
        $this->actor['role'] = 'admin';
        $db = db_connect();
        $db->query('CREATE TABLE ' . $db->prefixTable('passports') . ' (media_id INTEGER, event_name TEXT, date_taken TEXT)');
        $db->table('files')->where('id', 1)->update([
            'classification' => 'EXPO Activities', 'detected_objects' => 'event: EXPO Activities',
            'tags' => '2026, University Event, Digital Expo, EXPO Activities',
        ]);
        $db->table('files')->where('id', 3)->update(['classification' => 'Unclassified university media']);
        $db->table('passports')->insertBatch([
            ['media_id' => 1, 'event_name' => 'Dal-uy Events', 'date_taken' => '2024-01-17'],
            ['media_id' => 2, 'event_name' => 'Dal-uy Events', 'date_taken' => null],
            ['media_id' => 3, 'event_name' => 'Dal-uy Events', 'date_taken' => null],
        ]);
        $db->resetDataCache();
        $this->assertSame([2], $this->ids($this->page(['q' => 'dal'])));
        $this->assertSame([1], $this->ids($this->page(['q' => 'expo'])));
        $this->assertSame([1], $this->ids($this->page(['q' => '2024-01-17'])));

        // A real filename or folder match still counts, even after review.
        $db->table('files')->where('id', 1)->update(['filename' => 'dal-uy-poster.jpg']);
        $this->assertSame([2, 1], $this->ids($this->page(['q' => 'dal'])));
        $db->table('files')->where('id', 1)->update(['filename' => 'photo-1.jpg']);
        $db->table('folder')->where('id', 1)->update(['name' => 'Dal-uy', 'path' => 'Dal-uy']);
        $this->assertSame(range(45, 26), $this->ids($this->page(['q' => 'dal'])));
        $this->assertStringContainsString('45 files', $this->page(['q' => 'dal']));
    }

    public function testAdminPaginationClampsPagesAndKeepsLocatedFileVisible(): void
    {
        $this->actor['role']='admin';
        $this->assertSame(range(45,26),$this->ids($this->page(['folder'=>1,'page'=>-1])));
        $html=$this->page(['folder'=>1,'page'=>999]);
        $this->assertSame(range(5,1),$this->ids($html));
        $this->assertStringContainsString('Showing 41–45 of 45 files',$html);
        $located=$this->ids($this->page(['folder'=>1,'locate'=>1]));
        $this->assertCount(20,$located);
        $this->assertSame(1,$located[0]);
        $this->assertStringContainsString('Showing 0–0 of 0 files',$this->page(['folder'=>1,'type'=>'png']));
    }

    public function testRecentPageReadsTheRequestAndRendersActivity(): void
    {
        $this->actor['role'] = 'admin';
        $db = db_connect();
        foreach (['employee_id INTEGER', 'last_action TEXT', 'last_action_at TEXT'] as $column) {
            $db->query('ALTER TABLE '.$db->prefixTable('files').' ADD COLUMN '.$column);
        }
        foreach (['employee_id INTEGER', 'name TEXT'] as $column) {
            $db->query('ALTER TABLE '.$db->prefixTable('user').' ADD COLUMN '.$column);
        }
        $request = new IncomingRequest(config('App'), new URI('http://example.com/'), null, new UserAgent());
        $request->setGlobal('get', ['page'=>3]);
        $controller = new WorkspaceController();
        $controller->initController($request, new Response(config('App')), service('logger'));

        $html = $controller->recent();
        $this->assertStringContainsString('Recent media activity', $html);
        $this->assertStringContainsString('48 files', $html);
        $this->assertCount(8, $this->ids($html));
        $this->assertStringContainsString('page=3" aria-current="page"', $html);
    }

    public function testPublishedFilesPageLoadsItsModelsAndRenders(): void
    {
        $this->actor['role'] = 'admin';
        $db = db_connect();
        foreach (['files', 'folder'] as $table) {
            $db->query('ALTER TABLE '.$db->prefixTable($table).' ADD COLUMN published_at TEXT');
        }
        $request = new IncomingRequest(config('App'), new URI('http://example.com/'), null, new UserAgent());
        $request->setGlobal('get', []);
        $controller = new WorkspaceController();
        $controller->initController($request, new Response(config('App')), service('logger'));

        $html = $controller->publishedFiles();
        $this->assertStringContainsString('<title>Published Files</title>', $html);
        $this->assertCount(0, $this->ids($html));
    }
}
