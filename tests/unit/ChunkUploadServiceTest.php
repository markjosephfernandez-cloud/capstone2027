<?php

use App\Libraries\AssembledUpload;
use App\Services\ChunkUploadService;
use App\Services\StorageService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Storage;

final class ChunkUploadServiceTest extends CIUnitTestCase
{
    private string $root;
    private ChunkUploadService $chunks;
    private const ID = '0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/greyhound-chunks-' . bin2hex(random_bytes(6));
        mkdir($this->root);
        $this->chunks = new ChunkUploadService($this->root . '/chunks');
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        rmdir($this->root);
        parent::tearDown();
    }

    private function part(string $data): string
    {
        $path = $this->root . '/incoming';
        file_put_contents($path, $data);
        clearstatcache(true, $path);
        return $path;
    }

    public function testPartsAndFinalReceiptCanBeRetriedWithoutDuplicatingContentOrProcessing(): void
    {
        $calls = 0;
        $complete = function (AssembledUpload $file) use (&$calls): array {
            $calls++;
            $this->assertSame('abcdef', file_get_contents($file->getTempName()));
            return ['status' => 200, 'payload' => ['ok' => true, 'uploaded' => 1]];
        };
        $first = $this->chunks->accept(1, self::ID, 6, 0, $this->part('abc'), ['name' => 'photo.jpg'], $complete);
        $retry = $this->chunks->accept(1, self::ID, 6, 0, $this->part('abc'), ['name' => 'photo.jpg'], $complete);
        $this->assertSame($first, $retry);
        $this->assertSame(3, $first['payload']['nextOffset']);
        $last = $this->chunks->accept(1, self::ID, 6, 3, $this->part('def'), ['name' => 'photo.jpg'], $complete);
        $retry = $this->chunks->accept(1, self::ID, 6, 3, $this->part('def'), ['name' => 'photo.jpg'], $complete);
        $this->assertSame($last, $retry);
        $this->assertTrue($last['payload']['complete']);
        $this->assertSame(1, $calls);
        $this->assertSame([], glob($this->root . '/chunks/*.part'));
    }

    public function testAnotherUserCannotContinueAnUpload(): void
    {
        $complete = static fn () => [];
        $this->chunks->accept(1, self::ID, 6, 0, $this->part('abc'), ['name' => 'photo.jpg'], $complete);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('expired');
        $this->chunks->accept(2, self::ID, 6, 3, $this->part('def'), ['name' => 'photo.jpg'], $complete);
    }

    public function testOutOfOrderPartsAreRejected(): void
    {
        $this->chunks->accept(1, self::ID, 9, 0, $this->part('abc'), ['name' => 'photo.jpg'], static fn () => []);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('out of order');
        $this->chunks->accept(1, self::ID, 9, 6, $this->part('ghi'), ['name' => 'photo.jpg'], static fn () => []);
    }

    public function testChangedUploadMetadataIsRejected(): void
    {
        $this->chunks->accept(1, self::ID, 6, 0, $this->part('abc'), ['name' => 'photo.jpg'], static fn () => []);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('details changed');
        $this->chunks->accept(1, self::ID, 6, 3, $this->part('def'), ['name' => 'renamed.jpg'], static fn () => []);
    }

    public function testPathTraversalInFilenameIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('filename is invalid');
        $this->chunks->accept(1, self::ID, 3, 0, $this->part('abc'), ['name' => '../outside.jpg'], static fn () => []);
    }

    public function testExpiredPartialUploadsAreRemoved(): void
    {
        $this->chunks->accept(1, self::ID, 6, 0, $this->part('abc'), ['name' => 'photo.jpg'], static fn () => []);
        foreach (glob($this->root . '/chunks/*.json') as $path) touch($path, time() - 90000);
        $this->chunks->pruneExpired();
        $this->assertSame([], glob($this->root . '/chunks/*.part'));
        $this->assertSame([], glob($this->root . '/chunks/*.json'));
    }

    public function testFileAbove100MbIsAssembledAndStoredWithNoSizeCap(): void
    {
        $config = new class extends Storage {
            public string $testRoot;
            public function root(): string { return $this->testRoot; }
            public function maxUploadBytes(): int { return 0; }
        };
        $config->testRoot = $this->root . '/stored';
        $storage = new StorageService($config);
        $total = 101 * 1048576 + 17;
        $hash = hash_init('sha256');
        $stored = null;
        for ($offset = 0; $offset < $total; $offset += $length) {
            $length = min(ChunkUploadService::MAX_CHUNK_BYTES, $total - $offset);
            $data = str_repeat('x', $length);
            if ($offset === 0) $data = "%PDF-1.4\n" . substr($data, 9);
            hash_update($hash, $data);
            $result = $this->chunks->accept(1, self::ID, $total, $offset, $this->part($data), ['name' => 'large.pdf'], function (AssembledUpload $file) use ($storage, &$stored): array {
                $stored = $storage->storeUpload($file);
                $this->assertTrue($file->hasMoved());
                return ['status' => 200, 'payload' => ['ok' => true, 'uploaded' => 1]];
            });
        }
        $this->assertTrue($result['payload']['complete']);
        $this->assertSame($total, $stored['size']);
        $this->assertSame('application/pdf', $stored['mimeType']);
        $this->assertSame(hash_final($hash), hash_file('sha256', $stored['filepath']));
    }

    public function testAssembledUploadStillEnforcesFileTypes(): void
    {
        $config = new class extends Storage {
            public function maxUploadBytes(): int { return 0; }
        };
        $file = new AssembledUpload($this->part('<?php echo "unsafe";'), 'script.php');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('file type is not allowed');
        (new StorageService($config))->storeUpload($file);
    }

    public function testCancelRemovesPartialDataAndRejectsLateParts(): void
    {
        $this->chunks->accept(1, self::ID, 6, 0, $this->part('abc'), ['name' => 'photo.jpg'], static fn () => []);
        $this->assertTrue($this->chunks->cancel(1, self::ID)['cancelled']);
        $this->assertSame([], glob($this->root . '/chunks/*.part'));
        $this->assertTrue($this->chunks->cancel(1, self::ID)['cancelled']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cancelled');
        $this->chunks->accept(1, self::ID, 6, 3, $this->part('def'), ['name' => 'photo.jpg'], static fn () => []);
    }

    public function testCancelBeforeFirstPartPreventsALateRequestFromStarting(): void
    {
        $this->chunks->cancel(1, self::ID);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cancelled');
        $this->chunks->accept(1, self::ID, 6, 0, $this->part('abc'), ['name' => 'photo.jpg'], static fn () => []);
    }

    public function testCancelCannotDeleteAnotherOwnersUpload(): void
    {
        $this->chunks->accept(1, self::ID, 6, 0, $this->part('abc'), ['name' => 'photo.jpg'], static fn () => []);
        $this->chunks->cancel(2, self::ID);
        $result = $this->chunks->accept(1, self::ID, 6, 3, $this->part('def'), ['name' => 'photo.jpg'], static fn () => ['status' => 200, 'payload' => ['ok' => true]]);
        $this->assertTrue($result['payload']['complete']);
    }

    public function testCancelPreservesAlreadySavedFilesAndReportsCompletion(): void
    {
        $this->chunks->accept(1, self::ID, 3, 0, $this->part('abc'), ['name' => 'photo.jpg'], function (AssembledUpload $file): array {
            $file->move($this->root, 'saved.jpg');
            return ['status' => 200, 'payload' => ['ok' => true, 'uploaded' => 1]];
        });
        $result = $this->chunks->cancel(1, self::ID);
        $this->assertFalse($result['cancelled']);
        $this->assertTrue($result['complete']);
        $this->assertSame(1, $result['result']['uploaded']);
        $this->assertSame('abc', file_get_contents($this->root . '/saved.jpg'));
    }
}
