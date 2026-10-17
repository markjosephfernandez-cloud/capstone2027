<?php
use CodeIgniter\Test\CIUnitTestCase;
use App\Services\IFoundAnalysisQueue;

final class IFoundAnalysisQueueTest extends CIUnitTestCase
{
    public function testAnalysisEndpointsRejectUnauthorizedAndEmptySelections(): void
    {
        foreach ([[[],401],[['isLoggedIn'=>true,'role'=>'department'],403],[['isLoggedIn'=>true,'role'=>'super_admin','employee_id'=>7],422]] as [$actor,$expected]) {
            $session = $this->createMock(\CodeIgniter\Session\Session::class);
            $session->method('get')->willReturn($actor);
            \Config\Services::injectMock('session',$session);
            $request = new \CodeIgniter\HTTP\IncomingRequest(config('App'),new \CodeIgniter\HTTP\URI('http://example.com/'),null,new \CodeIgniter\HTTP\UserAgent());
            $request->setGlobal('post',[]); $request->setGlobal('get',[]);
            $controller = new \App\Controllers\Web\WorkspaceController();
            $controller->initController($request,new \CodeIgniter\HTTP\Response(config('App')),service('logger'));
            $this->assertSame($expected,$controller->analyzeSelectedImages()->getStatusCode());
            $this->assertSame($expected,$controller->selectedAnalysisStatus()->getStatusCode());
        }
    }

    public function testOnlyMissingActiveImagesAreQueuedAndFailedJobsCanRetry(): void
    {
        $db = db_connect(); $this->assertSame(':memory:', $db->database);
        $forge = \Config\Database::forge($db);
        $schemas = [
            'files'=>['id','filename','filetype','mime_type','is_deleted'],
            'ai_event_detections'=>['id','media_id','reviewed_at'],
            'server_media'=>['id','file_id','is_deleted'],
            'server_analysis_jobs'=>['id','media_id','status','attempts','updated_at','last_error'],
        ];
        try {
            foreach ($schemas as $name=>$columns) {
                $forge->dropTable($name,true);
                foreach ($columns as $column) $forge->addField([$column=>['type'=>in_array($column,['id','media_id','file_id','is_deleted','attempts'])?'INTEGER':'TEXT','null'=>true]]);
                if ($name === 'server_analysis_jobs') $forge->addUniqueKey('media_id');
                $forge->createTable($name);
            }
            foreach (range(1,7) as $id) {
                $db->table('files')->insert(['id'=>$id,'filename'=>'image'.$id.'.jpg','filetype'=>$id===4?'mp4':'jpg','mime_type'=>$id===4?'video/mp4':'image/jpeg','is_deleted'=>$id===5?1:0]);
                if ($id !== 7) $db->table('server_media')->insert(['id'=>$id,'file_id'=>$id,'is_deleted'=>0]);
            }
            $db->table('ai_event_detections')->insertBatch([['id'=>2,'media_id'=>2,'reviewed_at'=>null],['id'=>3,'media_id'=>3,'reviewed_at'=>'2026-10-07 01:00:00']]);
            $db->table('server_analysis_jobs')->insert(['media_id'=>6,'status'=>'failed','attempts'=>3,'last_error'=>'Offline']);
            $queue = new IFoundAnalysisQueue();
            $result = $queue->enqueue(range(1,7));
            $this->assertSame([1,6],$result['queued']);
            $this->assertSame(4,$result['skipped']);
            $this->assertSame(1,$result['unavailable']);
            $this->assertSame(2,$db->table('server_analysis_jobs')->countAllResults());
            $db->table('server_analysis_jobs')->where('media_id',1)->update(['attempts'=>1]);
            $queue->enqueue([1,6]);
            $this->assertSame(2,$db->table('server_analysis_jobs')->countAllResults());
            $job = $db->table('server_analysis_jobs')->where('media_id',1)->get()->getRowArray();
            $this->assertSame(1,(int)$job['attempts']);
            $this->assertSame(2,$db->table('ai_event_detections')->countAllResults());
            $db->table('server_analysis_jobs')->where('media_id',1)->update(['status'=>'complete']);
            $db->table('server_analysis_jobs')->where('media_id',6)->update(['status'=>'failed','attempts'=>1]);
            $this->assertSame(['complete','retrying'],array_column($queue->status([1,6]),'status'));
            $db->table('server_analysis_jobs')->where('media_id',6)->update(['attempts'=>3]);
            $this->assertSame('failed',$queue->status([6])[0]['status']);
        } finally {
            foreach (array_keys($schemas) as $table) $forge->dropTable($table,true);
            $db->resetDataCache();
        }
    }
}
