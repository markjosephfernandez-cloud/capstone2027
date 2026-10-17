<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\Storage;

/** @internal */
final class StorageConfigPathTest extends CIUnitTestCase
{
    public function testRelativeEnvironmentStoragePathsAlwaysResolveUnderProjectRoot(): void
    {
        $originalStorage = $_ENV['greyhound.storageRoot'] ?? null;
        $originalDepartment = $_ENV['greyhound.departmentStorageRoot'] ?? null;
        $_ENV['greyhound.storageRoot'] = 'writable/uploads';
        $_ENV['greyhound.departmentStorageRoot'] = 'writable/Department';

        try {
            $config = new Storage();

            $this->assertSame(
                ROOTPATH . 'writable' . DIRECTORY_SEPARATOR . 'uploads',
                $config->root()
            );
            $this->assertSame(
                ROOTPATH . 'writable' . DIRECTORY_SEPARATOR . 'Department',
                $config->departmentRoot()
            );
        } finally {
            $this->restoreEnvironment('greyhound.storageRoot', $originalStorage);
            $this->restoreEnvironment('greyhound.departmentStorageRoot', $originalDepartment);
        }
    }

    public function testConfiguredScanLimitIsNotReducedToFiveFiles(): void
    {
        $originalLimit = $_ENV['greyhound.maxScanFiles'] ?? null;
        $_ENV['greyhound.maxScanFiles'] = '10000';

        try {
            $this->assertSame(10000, (new Storage())->maxScanFiles());
        } finally {
            $this->restoreEnvironment('greyhound.maxScanFiles', $originalLimit);
        }
    }

    public function testScanLimitRetainsSafetyBounds(): void
    {
        $originalLimit = $_ENV['greyhound.maxScanFiles'] ?? null;

        try {
            $_ENV['greyhound.maxScanFiles'] = '0';
            $this->assertSame(1, (new Storage())->maxScanFiles());

            $_ENV['greyhound.maxScanFiles'] = '90000';
            $this->assertSame(50000, (new Storage())->maxScanFiles());
        } finally {
            $this->restoreEnvironment('greyhound.maxScanFiles', $originalLimit);
        }
    }

    public function testScanBatchSizeDefaultsToTenAndRetainsSafetyBounds(): void
    {
        $originalBatchSize = $_ENV['greyhound.scanBatchSize'] ?? null;

        try {
            unset($_ENV['greyhound.scanBatchSize']);
            $this->assertSame(10, (new Storage())->scanBatchSize());

            $_ENV['greyhound.scanBatchSize'] = '25';
            $this->assertSame(25, (new Storage())->scanBatchSize());

            $_ENV['greyhound.scanBatchSize'] = '0';
            $this->assertSame(1, (new Storage())->scanBatchSize());

            $_ENV['greyhound.scanBatchSize'] = '5000';
            $this->assertSame(1000, (new Storage())->scanBatchSize());
        } finally {
            $this->restoreEnvironment('greyhound.scanBatchSize', $originalBatchSize);
        }
    }

    private function restoreEnvironment(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_ENV[$key]);
            return;
        }

        $_ENV[$key] = $value;
    }
}
