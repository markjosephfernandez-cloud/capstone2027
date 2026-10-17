<?php

use App\Services\StorageService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Storage;

/** @internal */
final class ServerScanFeatureTest extends CIUnitTestCase
{
    public function testIFoundViewContainsServerScanProgressWorkflow(): void
    {
        $view = file_get_contents(APPPATH . 'Views/workspace/ifound.php');

        $this->assertIsString($view);
        $this->assertStringContainsString('id="ifoundScanButton"', $view);
        $this->assertStringContainsString('id="ifoundScanProgressBar"', $view);
        $this->assertStringContainsString('id="ifoundScanProgressTrack"', $view);
        $this->assertStringContainsString('workspace/ifound/scan/start', $view);
        $this->assertStringContainsString('workspace/ifound/scan/step', $view);
        $this->assertStringContainsString('workspace/ifound/scan/token', $view);
        $this->assertStringContainsString('View Scanned Folder', $view);
        $this->assertStringContainsString('while (!payload.complete)', $view);
        $this->assertStringContainsString('await refreshToken()', $view);
        $this->assertStringContainsString('data-bs-target="#ifoundScanPicker"', $view);
        $this->assertStringContainsString("progressTrack.classList.add('d-none')", $view);
        $this->assertStringContainsString("progressTrack.classList.remove('d-none')", $view);
    }

    public function testIFoundViewSupportsBatchClassificationReview(): void
    {
        $view = file_get_contents(APPPATH . 'Views/workspace/ifound.php');
        $controller = file_get_contents(APPPATH . 'Controllers/Web/WorkspaceController.php');
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');

        $this->assertIsString($view);
        $this->assertStringContainsString('id="ifoundSelectAll"', $view);
        $this->assertStringContainsString('id="ifoundSelectedCount"', $view);
        $this->assertStringContainsString('id="ifoundReviewSelected"', $view);
        $this->assertStringContainsString('id="ifoundImagePreviewModal"', $view);
        $this->assertStringContainsString('id="ifoundImagePreviewTitle">Image preview</h5><button', $view);
        $this->assertStringContainsString('class="ifound-image-number"', $view);
        $this->assertStringContainsString("allSelected ? 'Unselect all' : 'Select all'", $view);
        $this->assertStringContainsString('var pageSize = 3;', $view);
        $this->assertStringContainsString('modalItems.slice(pageStart, pageStart + pageSize)', $view);
        $this->assertStringNotContainsString("row.classList.toggle('table-primary'", $view);
        $this->assertStringNotContainsString('<th class="text-end">Actions</th>', $view);
        $this->assertStringContainsString('public function reviewEvents()', $controller);
        $this->assertStringContainsString("workspace/ifound/review', 'Web\\\\WorkspaceController::reviewEvents", $routes);
    }

    public function testStorageCopiesServerFileWithoutRemovingOriginal(): void
    {
        $temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'greyhound-scan-test-' . bin2hex(random_bytes(6));
        $sourceRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'source';
        $storageRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'storage';
        mkdir($sourceRoot, 0775, true);
        mkdir($storageRoot, 0775, true);
        $source = $sourceRoot . DIRECTORY_SEPARATOR . 'event.png';
        file_put_contents($source, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='));

        $config = new class extends Storage {
            public string $testRoot = '';

            public function root(): string
            {
                return $this->testRoot;
            }
        };
        $config->testRoot = $storageRoot;

        try {
            $stored = (new StorageService($config))->storeServerCopy($source, 'iFound Scanned Files');

            $this->assertFileExists($source);
            $this->assertFileExists($stored['filepath']);
            $this->assertSame(hash_file('sha256', $source), hash_file('sha256', $stored['filepath']));
            $this->assertSame('writable/uploads/iFound Scanned Files/event.png', (new StorageService($config))->databasePath($stored['filepath']));
            $this->assertStringStartsWith(realpath($storageRoot), $stored['filepath']);
            $this->assertSame('image/png', $stored['mimeType']);
        } finally {
            $this->removeTemporaryDirectory($temporaryRoot);
        }
    }

    public function testScannerIgnoresOperatingSystemMetadataPaths(): void
    {
        $method = new ReflectionMethod(\App\Services\ServerScanService::class, 'shouldIgnoreSourcePath');
        $scanner = new \App\Services\ServerScanService();

        $this->assertTrue($method->invoke($scanner, '.DS_Store'));
        $this->assertTrue($method->invoke($scanner, 'event/.hidden/photo.jpg'));
        $this->assertTrue($method->invoke($scanner, '__MACOSX/photo.jpg'));
        $this->assertTrue($method->invoke($scanner, 'Thumbs.db'));
        $this->assertTrue($method->invoke($scanner, 'desktop.ini'));
        $this->assertFalse($method->invoke($scanner, 'Graduation/photo.jpg'));
    }

    public function testSelectedScanRejectsTraversalLinksAndNonImagesAndKeepsExactSelection(): void
    {
        $root = sys_get_temp_dir() . '/greyhound-scan-test-' . bin2hex(random_bytes(6));
        mkdir($root . '/Event', 0775, true);
        $root = realpath($root);
        $image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        file_put_contents($root . '/Event/selected.png', $image);
        file_put_contents($root . '/Event/other.png', $image);
        file_put_contents($root . '/text.txt', 'not an image');
        symlink($root . '/Event', $root . '/linked');
        $config = new class extends Storage {
            public string $testRoot;
            public function scanSourceRoot(): string { return $this->testRoot; }
            public function root(): string { return $this->testRoot . '/managed'; }
            public function scannerEndpoint(): string { return ''; }
            public function scanBatchSize(): int { return 2; }
        };
        $config->testRoot = $root;
        $scanner = new \App\Services\ServerScanService($config, new StorageService($config));
        $validate = new ReflectionMethod($scanner, 'validateSelection');
        try {
            $this->assertSame(['Event/selected.png'], $validate->invoke($scanner, $root, ['Event/selected.png', 'Event/selected.png']));
            foreach ([[], ['../outside.png'], ['/etc/passwd'], ['linked/selected.png'], ['text.txt'], ['missing.png'], ['Event/selected.png', 'Event/other.png', 'third.png']] as $paths) {
                try { $validate->invoke($scanner, $root, $paths); $this->fail('Invalid selection accepted.'); }
                catch (RuntimeException $error) { $this->assertNotEmpty($error->getMessage()); }
            }
            $listing = $scanner->browse('Event', 'selected');
            $this->assertSame(['Event/selected.png'], array_column($listing['items'], 'path'));
            $this->assertNotContains('linked', array_column($scanner->browse()['items'], 'path'));
        } finally {
            unlink($root . '/linked');
            $this->removeTemporaryDirectory($root);
        }
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        $temporaryBase = rtrim(realpath(sys_get_temp_dir()) ?: sys_get_temp_dir(), "\\/");
        $resolved = realpath($directory);
        if ($resolved === false || ! str_starts_with(strtolower($resolved), strtolower($temporaryBase . DIRECTORY_SEPARATOR . 'greyhound-scan-test-'))) {
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

    public function testDisconnectedNetworkInboxIsNotCreatedAsAnEmptyLocalDirectory(): void
    {
        $root = sys_get_temp_dir().'/greyhound-scan-test-'.bin2hex(random_bytes(6));
        $config = new class extends Storage {
            public string $testRoot;
            public function scanSourceRoot(): string { return $this->testRoot; }
            public function scannerEndpoint(): string { return 'afp://example.test/creative'; }
        };
        $config->testRoot = $root;
        try {
            $method = new ReflectionMethod(\App\Services\ServerScanService::class,'sourceRoot');
            try {
                $method->invoke(new \App\Services\ServerScanService($config),true);
                $this->fail('A disconnected network share must be reported as unavailable.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('mounted',$error->getMessage());
                $this->assertDirectoryDoesNotExist($root);
            }
        } finally {
            $this->removeTemporaryDirectory($root);
        }
    }

    public function testLocalScanInboxCanStillBeCreated(): void
    {
        $root = sys_get_temp_dir().'/greyhound-scan-test-'.bin2hex(random_bytes(6));
        $config = new class extends Storage {
            public string $testRoot;
            public function scanSourceRoot(): string { return $this->testRoot; }
            public function scannerEndpoint(): string { return ''; }
        };
        $config->testRoot = $root;
        try {
            $method = new ReflectionMethod(\App\Services\ServerScanService::class,'sourceRoot');
            $this->assertSame(realpath(sys_get_temp_dir()).'/'.basename($root),$method->invoke(new \App\Services\ServerScanService($config),true));
            $this->assertDirectoryExists($root);
        } finally {
            $this->removeTemporaryDirectory($root);
        }
    }
}
