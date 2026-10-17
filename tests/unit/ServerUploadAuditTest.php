<?php
use App\Services\ServerUploadAuditService;
use CodeIgniter\Test\CIUnitTestCase;
final class ServerUploadAuditTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $db=db_connect();
        $this->assertSame('SQLite3',$db->DBDriver);
        $this->assertSame(':memory:',$db->database);
        $forge=\Config\Database::forge();
        $forge->dropTable('server_upload_audit_batches',true);
        $forge->dropTable('audit_logs',true);
        $forge->addField(['id'=>['type'=>'INTEGER','auto_increment'=>true], 'user_id'=>['type'=>'TEXT'],
            'user_name'=>['type'=>'TEXT','null'=>true], 'user_role'=>['type'=>'TEXT','null'=>true],
            'department'=>['type'=>'TEXT','null'=>true], 'module'=>['type'=>'TEXT'], 'action'=>['type'=>'TEXT'],
            'target_type'=>['type'=>'TEXT'], 'target_id'=>['type'=>'INTEGER','null'=>true],
            'description'=>['type'=>'TEXT'], 'ip_address'=>['type'=>'TEXT','null'=>true], 'created_at'=>['type'=>'TEXT']]);
        $forge->addKey('id',true);$forge->createTable('audit_logs');
        $db->table('audit_logs')->insert(['user_id'=>'1','module'=>'auth','action'=>'LOGIN','target_type'=>'user','description'=>'Old log','created_at'=>'2026-01-01 00:00:00','ip_address'=>'192.0.2.1']);
        require_once APPPATH . 'Database/Migrations/2026-10-14-100000_AddUploadBatchAudit.php';
        (new \App\Database\Migrations\AddUploadBatchAudit())->up();
    }
    protected function tearDown(): void
    {
        $forge=\Config\Database::forge();$forge->dropTable('server_upload_audit_batches',true);$forge->dropTable('audit_logs',true);
        parent::tearDown();
    }
    public function testBatchAggregates200FilesAndRetriesWithoutDuplicatingLogs(): void
    {
        $db=db_connect();$service=new ServerUploadAuditService($db);
        $actor=['employee_id'=>7,'name'=>'Uploader','role'=>'admin','department'=>'CCS'];
        for($i=0;$i<200;$i++) $service->record($actor,['upload_batch_id'=>'batch0001','audit_total'=>200,'audit_item_id'=>(string)$i], 'Events/'.$i.'.jpg', $i<195);
        $query=$db->table('audit_logs')->where('module','upload');
        $this->assertSame(1,$query->countAllResults(false));
        $row=$query->get()->getRowArray();
        $this->assertStringContainsString('195 uploaded, 5 failed, 0 pending',$row['description']);
        $this->assertStringContainsString('Uploaded: Events/0.jpg',$row['description']);
        $this->assertNull($row['ip_address']);
        $service->record($actor,['upload_batch_id'=>'batch0001','audit_total'=>200,'audit_item_id'=>'199'],'Events/199.jpg',true);
        $service->record($actor,['upload_batch_id'=>'batch0001','audit_total'=>200,'audit_item_id'=>'199'],'Events/199.jpg',false);
        $this->assertSame(1,$db->table('audit_logs')->where('module','upload')->countAllResults());
        $this->assertStringContainsString('196 uploaded, 4 failed',$db->table('audit_logs')->where('id',$row['id'])->get()->getRowArray()['description']);
        $service->record(['employee_id'=>8]+$actor,['upload_batch_id'=>'batch0001','audit_total'=>3,'audit_item_id'=>'0'],'Other.jpg',true);
        $this->assertSame(2,$db->table('audit_logs')->where('module','upload')->countAllResults());
        $this->assertStringContainsString('2 pending',$db->table('audit_logs')->where('user_id','8')->get()->getRowArray()['description']);
    }
    public function testAuditIpsAreErasedAndNeverRecordedAgain(): void
    {
        $db=db_connect();
        $this->assertSame(0,$db->table('audit_logs')->where('ip_address IS NOT NULL',null,false)->countAllResults());
        $request=$this->createMock(\CodeIgniter\HTTP\IncomingRequest::class);
        $request->expects($this->never())->method('getIPAddress');
        (new \App\Services\AuditService($db))->record(['employee_id'=>1],$request,'auth','LOGIN','user',1,'Login');
        $this->assertSame(0,$db->table('audit_logs')->where('ip_address IS NOT NULL',null,false)->countAllResults());
    }
}
