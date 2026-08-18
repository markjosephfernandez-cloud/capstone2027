<?php

use App\Controllers\Web\WorkspaceController;
use App\Models\FolderModel;
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

    public function testDuplicateModesUseSeparateCandidateScopes(): void
    {
        $db = db_connect();
        $db->table('files')->insertBatch([
            ['is_deleted' => 0, 'upload_batch_id' => 'current-batch', 'content_hash' => 'a', 'size' => 1],
            ['is_deleted' => 0, 'upload_batch_id' => 'older-batch', 'content_hash' => 'b', 'size' => 1],
            ['is_deleted' => 0, 'upload_batch_id' => null, 'content_hash' => 'c', 'size' => 1],
        ]);
        $method = new ReflectionMethod(WorkspaceController::class, 'duplicateCandidates');
        $controller = new WorkspaceController();

        $batchIds = array_map('intval', array_column($method->invoke($controller, 'current-batch', 'upload_batch')->findAll(), 'id'));
        $libraryIds = array_map('intval', array_column($method->invoke($controller, 'current-batch', 'media_library')->findAll(), 'id'));

        $this->assertSame([1], $batchIds);
        $this->assertSame([2, 3], $libraryIds);
    }

    public function testDuplicateRestoreReturnsCopyToOriginalFolder(): void
    {
        $method = new ReflectionMethod(WorkspaceController::class, 'duplicateRestorePayload');
        $payload = $method->invoke(new WorkspaceController(), [
            'filename' => 'portrait-copy.JPG',
            'display_title' => 'Portrait copy',
            'filepath' => 'C:/managed/Duplicate Queue/portrait-copy.JPG',
            'mime_type' => 'image/jpeg',
            'size' => 2048,
            'created_at' => '2026-08-13 10:00:00',
            'uploaded_by' => 20230107,
            'content_hash' => str_repeat('a', 64),
            'image_fingerprint' => '0123456789abcdef',
            'upload_batch_id' => 'batch-12345678',
            'original_file_id' => 42,
        ], ['id' => 42, 'folder_id' => 7], ['employee_id' => 1], '2026-08-13 11:00:00');

        $this->assertSame(7, $payload['folder_id']);
        $this->assertSame(42, $payload['duplicate_of']);
        $this->assertSame(20230107, $payload['employee_id']);
        $this->assertSame('jpg', $payload['filetype']);
        $this->assertSame('restored', $payload['last_action']);
        $this->assertSame('draft', $payload['release_status']);
    }
}
