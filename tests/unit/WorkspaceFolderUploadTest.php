<?php

use App\Controllers\Web\WorkspaceController;
use App\Models\FolderModel;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class WorkspaceFolderUploadTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $db = db_connect();
        $table = $db->prefixTable('folder');
        $db->query('DROP TABLE IF EXISTS ' . $table);
        $db->query('CREATE TABLE ' . $table . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            parent_id INTEGER NULL,
            name VARCHAR(120) NOT NULL,
            description TEXT NULL,
            path VARCHAR(500) NOT NULL,
            created_at DATETIME NULL,
            is_deleted INTEGER NOT NULL DEFAULT 0
        )');
        $filesTable = $db->prefixTable('files');
        $db->query('DROP TABLE IF EXISTS ' . $filesTable);
        $db->query('CREATE TABLE ' . $filesTable . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            is_deleted INTEGER NOT NULL DEFAULT 0,
            upload_batch_id VARCHAR(64) NULL,
            content_hash VARCHAR(64) NULL,
            filehash VARCHAR(64) NULL,
            size INTEGER NOT NULL DEFAULT 0
        )');
    }

    public function testFilenamePunctuationIsNotValidatedAsAFolderName(): void
    {
        $method = new ReflectionMethod(WorkspaceController::class, 'uploadDirectory');

        $directory = $method->invoke(
            new WorkspaceController(),
            'Pillar/2026',
            'ID Pictures/Villas, Allen Gabrielle D. 1.jpg',
        );

        $this->assertSame('Pillar/2026/ID Pictures', $directory);
    }

    public function testCommaIsAllowedInFolderNames(): void
    {
        $method = new ReflectionMethod(WorkspaceController::class, 'uploadDirectory');

        $directory = $method->invoke(
            new WorkspaceController(),
            'Pillar/2026',
            'Villas, Allen Gabrielle D. 1/portrait.jpg',
        );

        $this->assertSame('Pillar/2026/Villas, Allen Gabrielle D. 1', $directory);
    }

    public function testTraversalInFolderUploadPathRemainsRejected(): void
    {
        $method = new ReflectionMethod(WorkspaceController::class, 'uploadDirectory');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The folder path is invalid.');

        $method->invoke(new WorkspaceController(), 'Pillar/2026', '../outside.jpg');
    }

    public function testRejectedPhpUploadIsReportedBeforeTemporaryFileIsRead(): void
    {
        $method = new ReflectionMethod(WorkspaceController::class, 'assertUploadReady');
        $upload = new UploadedFile('', 'large-photo.jpg', 'image/jpeg', 0, UPLOAD_ERR_INI_SIZE);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('upload_max_filesize');

        $method->invoke(new WorkspaceController(), $upload);
    }

    public function testPhpUploadLimitUnitsAreConvertedToBytes(): void
    {
        $method = new ReflectionMethod(WorkspaceController::class, 'phpIniBytes');
        $controller = new WorkspaceController();

        $this->assertSame(100 * 1048576, $method->invoke($controller, '100M'));
        $this->assertSame(2 * 1073741824, $method->invoke($controller, '2G'));
        $this->assertSame(512 * 1024, $method->invoke($controller, '512K'));
    }

    public function testFolderHierarchyIsCreatedAndReusedForUploadedFiles(): void
    {
        $folders = new FolderModel();
        $baseId = $folders->insert([
            'name' => '2026',
            'parent_id' => null,
            'description' => '',
            'path' => 'Pillar/2026',
            'created_at' => '2026-08-13 00:00:00',
            'is_deleted' => 0,
        ], true);
        $baseFolder = $folders->find($baseId);
        $method = new ReflectionMethod(WorkspaceController::class, 'ensureUploadFolderHierarchy');
        $controller = new WorkspaceController();

        $firstLeafId = $method->invoke($controller, $baseFolder, 'CCS/Abing, Mike Exequiel/portrait-1.jpg');
        $secondLeafId = $method->invoke($controller, $baseFolder, 'CCS/Abing, Mike Exequiel/portrait-2.jpg');

        $rows = (new FolderModel())->orderBy('id', 'ASC')->findAll();
        $this->assertSame($firstLeafId, $secondLeafId);
        $this->assertCount(3, $rows);
        $this->assertSame(['2026', 'CCS', 'Abing, Mike Exequiel'], array_column($rows, 'name'));
        $this->assertSame('Pillar/2026/CCS/Abing, Mike Exequiel', $rows[2]['path']);
        $this->assertSame((int) $rows[1]['id'], (int) $rows[2]['parent_id']);
    }


}
