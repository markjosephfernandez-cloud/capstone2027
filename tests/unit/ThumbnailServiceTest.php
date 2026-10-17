<?php

use App\Services\ThumbnailService;
use CodeIgniter\Test\CIUnitTestCase;

final class ThumbnailServiceTest extends CIUnitTestCase
{
    private string $root;
    private ThumbnailService $thumbnails;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/greyhound-thumbnails-' . bin2hex(random_bytes(6));
        mkdir($this->root);
        $this->thumbnails = new ThumbnailService($this->root . '/cache');
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
        parent::tearDown();
    }

    private function photo(int $width = 1200, int $height = 600): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 80, 150, 210));
        $path = $this->root . '/photo.jpg';
        imagejpeg($image, $path, 95);
        imagedestroy($image);
        return $path;
    }

    public function testTimeBudgetCoversSourceInspectionAndRestoresAfterEarlyFailure(): void
    {
        $previous = (int) ini_get('max_execution_time');
        $service = new class extends ThumbnailService {
            public int $observedLimit = -1;

            protected function buildThumbnail(string $source): string
            {
                $this->observedLimit = (int) ini_get('max_execution_time');
                return parent::buildThumbnail($source);
            }
        };
        try {
            set_time_limit(30);
            try {
                $service->forFile($this->root . '/missing.jpg');
                $this->fail('Missing source must fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('The source image is unavailable.', $exception->getMessage());
            }
            $this->assertSame(0, $service->observedLimit, 'Source inspection must run inside the adjusted budget.');
            $this->assertSame('30', ini_get('max_execution_time'));
        } finally {
            set_time_limit($previous);
        }
    }

    public function testTiffUsesCachedJpegForThumbnailAndFullPreviewWithoutChangingOriginal(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') $this->markTestSkipped('Requires the macOS image converter.');
        $jpeg = $this->photo(1800, 900);
        $source = $this->root . '/camera photo.TIFF';
        $process = proc_open(['/usr/bin/sips', '-s', 'format', 'tiff', $jpeg, '--out', $source], [1=>['pipe','w'], 2=>['pipe','w']], $pipes);
        stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($process));
        $hash = hash_file('sha256', $source);
        $thumbnail = $this->thumbnails->forFile($source);
        $this->assertSame([400, 200], array_slice(getimagesize($thumbnail), 0, 2));
        $service = new \App\Services\CameraImagePreviewService($this->root . '/preview');
        $preview = $service->forFile($source);
        $this->assertSame([1600, 800], array_slice(getimagesize($preview), 0, 2));
        $this->assertSame('image/jpeg', getimagesize($preview)['mime']);
        touch($preview, 1000000000);
        $this->assertSame($preview, $service->forFile($source));
        $this->assertSame(1000000000, filemtime($preview));
        $this->assertSame($hash, hash_file('sha256', $source));
    }

    public function testInvalidCr2DoesNotPublishAPreview(): void
    {
        $source = $this->root . '/invalid.CR2';
        file_put_contents($source, 'not a camera image');
        $this->assertTrue(\App\Services\CameraImagePreviewService::supports($source));
        try {
            $this->thumbnails->forFile($source);
            $this->fail('Invalid camera files must be rejected.');
        } catch (\RuntimeException) {
            $this->assertSame([], glob($this->root . '/cache/camera/*.jpg'));
            $this->assertSame('not a camera image', file_get_contents($source));
        }
    }

    public function testThumbnailPreservesOriginalAndReusesCachedResult(): void
    {
        $source = $this->photo();
        $hash = hash_file('sha256', $source);
        $thumbnail = $this->thumbnails->forFile($source);
        $size = getimagesize($thumbnail);
        $this->assertSame([400, 200], [$size[0], $size[1]]);
        $this->assertSame('image/jpeg', $size['mime']);
        $this->assertLessThan(filesize($source), filesize($thumbnail));
        $this->assertSame($hash, hash_file('sha256', $source));
        touch($thumbnail, 1000000000);
        $this->assertSame($thumbnail, $this->thumbnails->forFile($source));
        clearstatcache(true, $thumbnail);
        $this->assertSame(1000000000, filemtime($thumbnail), 'A cache hit must not regenerate the image.');
    }

    public function testReplacingOriginalInvalidatesCachedThumbnail(): void
    {
        $source = $this->photo();
        $first = $this->thumbnails->forFile($source);
        $this->photo(600, 1200);
        touch($source, time() + 2);
        $second = $this->thumbnails->forFile($source);
        $size = getimagesize($second);
        $this->assertNotSame($first, $second);
        $this->assertSame([200, 400], [$size[0], $size[1]]);
    }

    public function testSmallImagesAreNotUpscaled(): void
    {
        $size = getimagesize($this->thumbnails->forFile($this->photo(80, 40)));
        $this->assertSame([80, 40], [$size[0], $size[1]]);
    }

    public function testExifOrientationIsApplied(): void
    {
        $source = $this->photo();
        $jpeg = file_get_contents($source);
        $exif = "Exif\0\0II" . pack('vVv', 42, 8, 1) . pack('vvVv', 0x0112, 3, 1, 6) . "\0\0" . pack('V', 0);
        file_put_contents($source, substr($jpeg, 0, 2) . "\xff\xe1" . pack('n', strlen($exif) + 2) . $exif . substr($jpeg, 2));
        $size = getimagesize($this->thumbnails->forFile($source));
        $this->assertSame([200, 400], [$size[0], $size[1]]);
    }

    public function testUnsupportedContentIsRejectedWithoutCaching(): void
    {
        $source = $this->root . '/invalid.jpg';
        file_put_contents($source, 'not an image');
        $this->expectException(RuntimeException::class);
        $this->thumbnails->forFile($source);
    }

    public function testCameraPhotoWorksUnder128MbLimitAndRestoresBudget(): void
    {
        $previousLimit = (string) ini_get('memory_limit');
        $previousExecutionLimit = (int) ini_get('max_execution_time');
        try {
            set_time_limit(30);
            ini_set('memory_limit', '256M');
            $source = $this->photo(5760, 3840);
            $hash = hash_file('sha256', $source);
            ini_set('memory_limit', '128M');
            $thumbnail = $this->thumbnails->forFile($source);
            $size = getimagesize($thumbnail);
            $this->assertSame([400, 267], [$size[0], $size[1]]);
            $this->assertSame('128M', ini_get('memory_limit'));
            $this->assertSame('30', ini_get('max_execution_time'));
            $this->assertSame($hash, hash_file('sha256', $source));
        } finally {
            ini_set('memory_limit', $previousLimit);
            set_time_limit($previousExecutionLimit);
        }
    }

    public function testFailedCameraImageDecodeAlsoRestoresBudget(): void
    {
        $previousLimit = (string) ini_get('memory_limit');
        $previousExecutionLimit = (int) ini_get('max_execution_time');
        try {
            set_time_limit(30);
            ini_set('memory_limit', '128M');
            // A valid PNG size header without image data exercises a decode failure.
            $source = $this->root . '/truncated.png';
            $header = 'IHDR' . pack('NNCCCCC', 5760, 3840, 8, 2, 0, 0, 0);
            file_put_contents($source, "\x89PNG\r\n\x1a\n" . pack('N', 13) . $header . pack('N', crc32($header)));
            try {
                $this->thumbnails->forFile($source);
                $this->fail('Truncated image should not decode.');
            } catch (RuntimeException $exception) {
                $this->assertSame('The source image could not be decoded.', $exception->getMessage());
            }
            $this->assertSame('128M', ini_get('memory_limit'));
            $this->assertSame('30', ini_get('max_execution_time'));
        } finally {
            ini_set('memory_limit', $previousLimit);
            set_time_limit($previousExecutionLimit);
        }
    }
}
