<?php
use App\Services\ServerUploadAuditService;
use CodeIgniter\Test\CIUnitTestCase;
final class ServerUploadAuditTest extends CIUnitTestCase
{
    public function testUploadDescriptionGroupsByParentAndPreservesSummaryAndNames(): void
    {
        $summary = 'Server Library upload batch: 3 uploaded, 1 failed, 0 pending or not received; 4 selected. Batch: batch0001.';
        $original = $summary . "\nUploaded: Event/one.jpg\nUploaded: Other/one.jpg"
            . "\nUploaded: Event/<two & three>.jpg\nFailed: Event/four.jpg";
        $formatted = \App\Libraries\UploadAuditDescription::format($original);
        $this->assertSame($summary . "\n\nEvent/\n  one.jpg\n  <two & three>.jpg\n  Failed: four.jpg"
            . "\n\nOther/\n  one.jpg", $formatted);
        $this->assertStringContainsString('&lt;two &amp; three&gt;.jpg', esc($formatted));
        $this->assertStringNotContainsString('<two & three>', esc($formatted));
    }

    public function testUploadDescriptionHandlesOneDirectoryRootFilesAndNotes(): void
    {
        $summary = 'Server Library upload batch: 2 uploaded, 0 failed, 0 pending or not received; 2 selected. Batch: batch0002.';
        $this->assertSame(
            $summary . "\n\nmark fernandez/carl Patula/mcthets/test/\n  001.jpg\n  002.jpg",
            \App\Libraries\UploadAuditDescription::format($summary
                . "\nUploaded: mark fernandez/carl Patula/mcthets/test/001.jpg"
                . "\nUploaded: mark fernandez/carl Patula/mcthets/test/002.jpg")
        );
        $note = 'Additional file details retained in upload batch records.';
        $this->assertSame($summary . "\n\n./\n  original.jpg\n\n" . $note,
            \App\Libraries\UploadAuditDescription::format($summary . "\nUploaded: original.jpg\n" . $note));
        $this->assertSame('Other activity', \App\Libraries\UploadAuditDescription::format('Other activity'));
    }

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

    public function testServerScanUpdatesOneEntryAcrossBatchesAndRetries(): void
    {
        $db = db_connect();
        $service = new \App\Services\AuditService($db);
        $actor = ['employee_id' => 7, 'name' => 'Scanner'];
        $results = [['path' => 'Event/one.jpg', 'status' => 'imported', 'message' => 'Scanned.']];
        $id = $service->recordServerScan($actor, null, $results, 3);
        $row = $db->table('audit_logs')->where('id', $id)->get()->getRowArray();
        $this->assertSame('SERVER_SCAN_IN_PROGRESS', $row['action']);
        $this->assertStringContainsString('Scanned 1 of 3 files', $row['description']);

        $results[] = ['path' => 'Event/two.jpg', 'status' => 'skipped', 'message' => 'Already scanned.'];
        $results[] = ['path' => 'Event/three.jpg', 'status' => 'failed', 'message' => 'Unavailable.'];
        for ($retry = 0; $retry < 2; ++$retry) {
            $this->assertSame($id, $service->recordServerScan($actor, $id, $results, 3));
        }
        $this->assertSame(1, $db->table('audit_logs')->where('module', 'metadata')->countAllResults());
        $row = $db->table('audit_logs')->where('id', $id)->get()->getRowArray();
        $this->assertSame('SERVER_SCAN_COMPLETED', $row['action']);
        $this->assertStringContainsString('1 successful, 1 skipped, 1 failed', $row['description']);
        foreach ($results as $result) {
            $this->assertStringContainsString($result['path'], $row['description']);
        }
        $otherId = $service->recordServerScan($actor, null, $results, 3);
        $this->assertNotSame($id, $otherId);
    }
}
