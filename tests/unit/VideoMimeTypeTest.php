<?php

use App\Services\VideoMimeType;
use CodeIgniter\Test\CIUnitTestCase;

final class VideoMimeTypeTest extends CIUnitTestCase
{
    public function testLargeVideoUsesHeaderWithoutChangingSource(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'video-mime-');
        try {
            $header = pack('N', 24) . 'ftypisom' . pack('N', 0) . 'isommp42';
            $handle = fopen($path, 'wb');
            fwrite($handle, $header);
            // Sparse 512 MB file exercises size independence without allocating 512 MB.
            fseek($handle, 512 * 1024 * 1024 - 1);
            fwrite($handle, "\0");
            fclose($handle);
            $this->assertSame('video/mp4', VideoMimeType::detect($path));
            $this->assertSame(512 * 1024 * 1024, filesize($path));
            $this->assertSame($header, file_get_contents($path, false, null, 0, strlen($header)));
        } finally { unlink($path); }
    }

    public function testDoesNotTrustVideoExtension(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'not-video-');
        try {
            file_put_contents($path, 'This is text, not a movie.');
            $this->assertSame('text/plain', VideoMimeType::detect($path));
        } finally { unlink($path); }
    }

    public function testQuickTimeHeaderIsRecognized(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'mov-header-');
        try {
            file_put_contents($path, pack('N', 20) . 'ftypqt  ' . pack('N', 0) . 'qt  ');
            $this->assertSame('video/quicktime', VideoMimeType::detect($path));
        } finally { unlink($path); }
    }

    public function testEmptyFileFailsCleanly(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'empty-video-');
        try {
            $this->expectException(RuntimeException::class);
            VideoMimeType::detect($path);
        } finally { unlink($path); }
    }
}
