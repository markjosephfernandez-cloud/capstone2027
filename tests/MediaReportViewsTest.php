<?php

use App\Database\Migrations\AddMediaReportViews;
use CodeIgniter\Test\CIUnitTestCase;

final class MediaReportViewsTest extends CIUnitTestCase
{
    public function testReportsExcludeTrashAvoidDuplicateFilesAndSummarizeCoverage(): void
    {
        $db = db_connect();
        $this->assertSame(':memory:', $db->database);
        $forge = \Config\Database::forge($db);
        $schemas = [
            'files' => 'id filename display_title filetype size dateupload employee_id classification is_deleted',
            'user' => 'employee_id name department',
            'passports' => 'id media_id department passport_uid needs_review',
            'server_media' => 'id file_id is_deleted',
            'server_analysis_jobs' => 'media_id status attempts',
        ];
        require_once APPPATH . 'Database/Migrations/2026-10-06-133138_AddMediaReportViews.php';
        $migration = new AddMediaReportViews($forge);
        try {
            foreach ($schemas as $name => $columns) {
                $forge->dropTable($name, true);
                foreach (explode(' ', $columns) as $column) {
                    $forge->addField([$column => ['type' => in_array($column, ['id','media_id','file_id','employee_id','size','is_deleted','needs_review','attempts']) ? 'INTEGER' : 'TEXT', 'null' => true]]);
                }
                $forge->createTable($name);
            }
            $db->table('user')->insert(['employee_id'=>7, 'name'=>'Staff', 'department'=>'CCS']);
            foreach ([1,2,3,4] as $id) $db->table('files')->insert(['id'=>$id,'filename'=>'file'.$id.'.jpg','size'=>1048576,'employee_id'=>$id === 4 ? null : 7,'is_deleted'=>$id === 3 ? 1 : 0]);
            $db->table('passports')->insertBatch([
                ['id'=>1,'media_id'=>1,'department'=>'Old department','passport_uid'=>'old','needs_review'=>0],
                ['id'=>2,'media_id'=>1,'department'=>'CCS','passport_uid'=>'latest','needs_review'=>1],
            ]);
            $db->table('server_media')->insertBatch([['id'=>1,'file_id'=>1,'is_deleted'=>0],['id'=>2,'file_id'=>1,'is_deleted'=>0],['id'=>3,'file_id'=>2,'is_deleted'=>0]]);
            $db->table('server_analysis_jobs')->insertBatch([['media_id'=>1,'status'=>'failed','attempts'=>3],['media_id'=>2,'status'=>'complete','attempts'=>1],['media_id'=>3,'status'=>'pending','attempts'=>0]]);
            $migration->up();
            $operational = $db->table('vw_media_processing_status')->orderBy('file_id')->get()->getResultArray();
            $this->assertCount(3, $operational);
            $this->assertSame('complete', $operational[0]['analysis_status']);
            $this->assertSame('latest', $operational[0]['passport_uid']);
            $this->assertSame('pending', $operational[1]['analysis_status']);
            $this->assertSame('Unassigned', $operational[2]['department']);
            $this->assertSame('not_queued', $operational[2]['analysis_status']);
            $summary = $db->table('vw_department_media_summary')->where('department','CCS')->get()->getRowArray();
            $this->assertSame(2, (int) $summary['total_files']);
            $this->assertSame(2097152, (int) $summary['total_storage_bytes']);
            $this->assertEquals(50, $summary['passport_coverage_percent']);
            $this->assertSame(1, (int) $summary['files_missing_passport']);
            $this->assertSame(2, (int) $summary['files_needing_attention']);
            $this->assertSame(4, $db->table('files')->countAllResults());
            helper('dashboard');
            $html = view('addons/media_processing_report', ['report' => ['available'=>true, 'rows'=>$operational, 'total'=>3, 'page'=>1, 'pages'=>2]]);
            $this->assertStringContainsString('Media Processing Status', $html);
            $this->assertStringContainsString('file1.jpg', $html);
            $this->assertStringContainsString('Missing', $html);
            $this->assertStringContainsString('processing_page=2', html_entity_decode($html, ENT_QUOTES | ENT_HTML5));
            $summaryHtml = view('admin/department_media_summary', ['rows'=>[$summary]]);
            $this->assertStringContainsString('Department Media Summary', $summaryHtml);
            $this->assertStringContainsString('50.00%', $summaryHtml);
            $migration->down();
            $this->assertSame(4, $db->table('files')->countAllResults());
        } finally {
            $migration->down();
            foreach (array_keys($schemas) as $table) $forge->dropTable($table, true);
            $db->resetDataCache();
        }
    }
}
