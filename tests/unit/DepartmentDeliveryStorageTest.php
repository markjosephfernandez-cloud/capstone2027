<?php

use App\Services\StorageService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Storage;

/** @internal */
final class DepartmentDeliveryStorageTest extends CIUnitTestCase
{
    public function testDepartmentDirectoryUsesDesignatedDepartmentName(): void
    {
        $temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'greyhound-department-test-' . bin2hex(random_bytes(6));
        mkdir($temporaryRoot, 0775, true);
        $storage = new StorageService($this->storageConfig($temporaryRoot));

        try {
            $directory = $storage->ensureDepartmentDirectory('CCS Department');

            $this->assertDirectoryExists($directory);
            $this->assertSame(
                realpath($temporaryRoot . DIRECTORY_SEPARATOR . 'Department' . DIRECTORY_SEPARATOR . 'CCS'),
                $directory
            );
            $this->assertSame('CCS', $storage->departmentFolderName('CCS Department'));
        } finally {
            $this->removeTemporaryDirectory($temporaryRoot);
        }
    }

    public function testDepartmentDeliveryCopiesOriginalAndPreservesFolderPath(): void
    {
        $temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'greyhound-department-test-' . bin2hex(random_bytes(6));
        $sourceRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'source';
        $storageRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'storage';
        mkdir($sourceRoot, 0775, true);
        mkdir($storageRoot, 0775, true);
        $source = $sourceRoot . DIRECTORY_SEPARATOR . 'event.png';
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));
        $storage = new StorageService($this->storageConfig($storageRoot));

        try {
            $stored = $storage->storeDepartmentCopy(
                $source,
                'College/Arts',
                'Events/Graduation'
            );

            $this->assertFileExists($source);
            $this->assertFileExists($stored['filepath']);
            $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $stored['filepath']));
            $databasePath = $storage->databasePath($stored['filepath']);
            $this->assertSame('writable/Department/College_Arts/Events/Graduation/event.png', $databasePath);
            $this->assertSame(realpath($stored['filepath']), $storage->resolveDatabaseFile($databasePath));
            $this->assertStringContainsString(
                'Department' . DIRECTORY_SEPARATOR . 'College_Arts' . DIRECTORY_SEPARATOR . 'Events' . DIRECTORY_SEPARATOR . 'Graduation',
                $stored['filepath']
            );
        } finally {
            $this->removeTemporaryDirectory($temporaryRoot);
        }
    }

    public function testDeleteDatabaseFileRemovesManagedPhysicalFile(): void
    {
        $temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'greyhound-department-test-' . bin2hex(random_bytes(6));
        $storage = new StorageService($this->storageConfig($temporaryRoot));
        $managedFile = $temporaryRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'delete-me.txt';
        mkdir(dirname($managedFile), 0775, true);
        file_put_contents($managedFile, 'delete me');

        try {
            $this->assertTrue($storage->deleteDatabaseFile($managedFile));
            $this->assertFileDoesNotExist($managedFile);
            $this->assertFalse($storage->deleteDatabaseFile($managedFile));
        } finally {
            $this->removeTemporaryDirectory($temporaryRoot);
        }
    }

    public function testPortableUploadPathResolvesAgainstConfiguredStorageRoot(): void
    {
        $temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'greyhound-department-test-' . bin2hex(random_bytes(6));
        $storage = new StorageService($this->storageConfig($temporaryRoot));
        $managedFile = $temporaryRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'Events' . DIRECTORY_SEPARATOR . 'photo.jpg';
        mkdir(dirname($managedFile), 0775, true);
        file_put_contents($managedFile, 'portable path');

        try {
            $this->assertSame('writable/uploads/Events/photo.jpg', $storage->databasePath($managedFile));
            $this->assertSame(realpath($managedFile), $storage->resolveDatabaseFile('writable/uploads/Events/photo.jpg'));
        } finally {
            $this->removeTemporaryDirectory($temporaryRoot);
        }
    }

    public function testDeleteDatabaseFileRejectsFileOutsideManagedStorage(): void
    {
        $temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'greyhound-department-test-' . bin2hex(random_bytes(6));
        $storage = new StorageService($this->storageConfig($temporaryRoot . DIRECTORY_SEPARATOR . 'managed'));
        $outsideFile = $temporaryRoot . DIRECTORY_SEPARATOR . 'keep-me.txt';
        mkdir($temporaryRoot, 0775, true);
        file_put_contents($outsideFile, 'keep me');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('outside the managed storage');
            $storage->deleteDatabaseFile($outsideFile);
        } finally {
            $this->assertFileExists($outsideFile);
            $this->removeTemporaryDirectory($temporaryRoot);
        }
    }

    private function storageConfig(string $root): Storage
    {
        $config = new class extends Storage {
            public string $testRoot = '';
            public string $testDepartmentRoot = '';

            public function root(): string
            {
                return $this->testRoot;
            }

            public function departmentRoot(): string
            {
                return $this->testDepartmentRoot;
            }
        };
        $config->testRoot = $root . DIRECTORY_SEPARATOR . 'uploads';
        $config->testDepartmentRoot = $root . DIRECTORY_SEPARATOR . 'Department';

        return $config;
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        $temporaryBase = rtrim(realpath(sys_get_temp_dir()) ?: sys_get_temp_dir(), "\\/");
        $resolved = realpath($directory);
        if ($resolved === false || ! str_starts_with(strtolower($resolved), strtolower($temporaryBase . DIRECTORY_SEPARATOR . 'greyhound-department-test-'))) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($resolved, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($resolved);
    }
}
