<?php

use App\Services\VisualMediaAnalysisService;
use CodeIgniter\Test\CIUnitTestCase;

final class VisualMediaAnalysisTest extends CIUnitTestCase
{
    public function testRemovedDuplicateTaskIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new VisualMediaAnalysisService())->analyzeAndStore(['id' => 1], ['duplicates']);
    }

    public function testValidatesBoxesLabelsScoresAndExpressionCategories(): void
    {
        $valid = ['label'=>'person', 'confidence'=>92.3, 'box'=>[0.1,0.1,0.8,0.9]];
        $task = VisualMediaAnalysisService::normalizeTask(['status'=>'complete','model'=>'test','items'=>[
            $valid, $valid + [], array_replace($valid, ['confidence'=>INF]),
            array_replace($valid, ['box'=>[0,0,2,1]]), array_replace($valid, ['label'=>'<script>']),
            array_replace($valid, ['box'=>[1,1,0,0]]),
        ]]);
        $this->assertCount(2, $task['items']);
        $this->assertSame('unavailable', VisualMediaAnalysisService::normalizeTask(null)['status']);
        $this->assertSame([], VisualMediaAnalysisService::normalizeTask(['status'=>'no_face','items'=>[$valid]], true)['items']);
        $this->assertSame([], VisualMediaAnalysisService::normalizeTask(['status'=>'complete','items'=>[$valid]], true)['items']);
    }

    public function testTagsUseConfidenceThresholdsAndDeduplicateByBestScore(): void
    {
        $tags = VisualMediaAnalysisService::automaticTags([
            'objects'=>['status'=>'complete','model'=>'yolo','items'=>[
                ['label'=>'person','confidence'=>55], ['label'=>'person','confidence'=>95], ['label'=>'chair','confidence'=>49],
            ]],
            'expressions'=>['status'=>'complete','model'=>'emotion','items'=>[
                ['label'=>'happy','confidence'=>85], ['label'=>'sad','confidence'=>79],
            ]],
        ]);
        $this->assertSame(['person','happy'], array_column($tags, 'label'));
        $this->assertSame(95, $tags[0]['confidence']);
        $this->assertSame('emotion', $tags[1]['model']);
    }

    public function testVisualEndpointRequiresStaffAndValidSelection(): void
    {
        foreach ([[[],401],[['isLoggedIn'=>true,'role'=>'department'],403],[['isLoggedIn'=>true,'role'=>'admin'],422]] as [$actor,$expected]) {
            $session = $this->createMock(\CodeIgniter\Session\Session::class);
            $session->method('get')->willReturn($actor);
            \Config\Services::injectMock('session',$session);
            $request = new \CodeIgniter\HTTP\IncomingRequest(config('App'),new \CodeIgniter\HTTP\URI('http://example.com/'),null,new \CodeIgniter\HTTP\UserAgent());
            $request->setGlobal('post',[]);
            $controller = new \App\Controllers\Web\VisualAnalysisController();
            $controller->initController($request,new \CodeIgniter\HTTP\Response(config('App')),service('logger'));
            $this->assertSame($expected,$controller->analyze()->getStatusCode());
        }
    }
    public function testPersistsTagsAndKeepsReviewedEventAndPreviousResultsOnModelFailure(): void
    {
        $db = db_connect(); $this->assertSame(':memory:', $db->database);
        $forge = \Config\Database::forge($db);
        $schemas = [
            'files'=>['id','is_deleted','filename','filepath','mime_type','filetype','content_hash','image_fingerprint','tags','classification','detected_objects','emotion'],
            'tag'=>['tag_id','file_id','tag','type','filepath'],
            'media_visual_analysis'=>['file_id','result_json','analyzed_at'],
        ];
        $path = tempnam(sys_get_temp_dir(), 'visual-persist-');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        try {
            foreach ($schemas as $table=>$columns) {
                $forge->dropTable($table, true);
                foreach ($columns as $column) $forge->addField([$column=>['type'=>in_array($column,['id','file_id','tag_id','is_deleted'])?'INTEGER':'TEXT','null'=>true]]);
                if ($table === 'media_visual_analysis') $forge->addKey('file_id', true);
                $forge->createTable($table);
            }
            $file = ['id'=>7,'is_deleted'=>0,'filename'=>'photo.png','filepath'=>$path,'mime_type'=>'image/png','filetype'=>'png','tags'=>'Campus','classification'=>'Reviewed event'];
            $db->table('files')->insert($file);
            $storage = $this->createMock(\App\Services\StorageService::class);
            $storage->method('resolveDatabaseFile')->willReturn($path);
            \Config\Services::injectMock('storage', $storage);
            $service = new class extends VisualMediaAnalysisService {
                public bool $offline = false;
                protected function requestAnalysis(string $path): array {
                    return ['objects'=>['status'=>$this->offline?'unavailable':'complete','model'=>'test','items'=>$this->offline?[]:[['label'=>'person','confidence'=>95,'box'=>[0,0,1,1]]]],
                        'expressions'=>['status'=>'no_face','items'=>[]]];
                }
            };
            $service->analyzeAndStore($file);
            $row = $db->table('files')->where('id',7)->get()->getRowArray();
            $this->assertSame('Campus, person',$row['tags']);
            $this->assertSame('person',$row['detected_objects']);
            $this->assertSame('Reviewed event',$row['classification']);
            $this->assertSame('person',$db->table('tag')->get()->getRowArray()['tag']);
            $service->offline = true;
            $service->analyzeAndStore($file);
            $row = $db->table('files')->where('id',7)->get()->getRowArray();
            $this->assertSame('Campus, person',$row['tags']);
            $this->assertSame('person',$row['detected_objects']);
            $this->assertSame(1,$db->table('media_visual_analysis')->countAllResults());
            $this->assertSame('unavailable',$service->resultsFor([7])[7]['objects']['status']);
        } finally {
            unlink($path);
            foreach (array_keys($schemas) as $table) $forge->dropTable($table,true);
            $db->resetDataCache();
        }
    }

    public function testIFoundResultEscapesLabelsAndShowsUnavailableState(): void
    {
        $html = view('workspace/ifound_visual_result', ['analysis'=>[
            'objects'=>['status'=>'unavailable','items'=>[]], 'expressions'=>['status'=>'no_face','items'=>[]],
            'duplicates'=>['items'=>[['file_id'=>1,'filename'=>'<script>bad</script>','type'=>'exact','similarity'=>100]],'checked'=>1],
            'auto_tags'=>[],
        ]]);
        $this->assertStringContainsString('Partial analysis', $html);
        $this->assertStringContainsString('No face detected.', $html);
        $this->assertStringNotContainsString('<script>bad</script>', $html);
        $this->assertStringNotContainsString('Duplicate candidates', $html);
        $this->assertStringNotContainsString('duplicate candidates', $html);
    }
}
