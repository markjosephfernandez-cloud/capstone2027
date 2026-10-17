<?php

use App\Services\VideoPreviewService;
use CodeIgniter\Test\CIUnitTestCase;

final class VideoPreviewServiceTest extends CIUnitTestCase
{
    private string $root;
    private string $ffmpeg;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['/opt/homebrew/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/usr/bin/ffmpeg'] as $binary) {
            if (is_executable($binary)) { $this->ffmpeg = $binary; break; }
        }
        if (! isset($this->ffmpeg)) $this->markTestSkipped('FFmpeg is required for conversion tests.');
        $this->root = sys_get_temp_dir() . '/greyhound-video-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($entries as $entry) $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
            rmdir($this->root);
        }
        parent::tearDown();
    }

    private function runCommand(array $command): string
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $this->assertSame(0, proc_close($process), $errors);
        return $output;
    }

    private function source(string $codec): string
    {
        $source = $this->root . '/video with spaces.mp4';
        $this->runCommand([$this->ffmpeg, '-v', 'error', '-y', '-f', 'lavfi', '-i', 'color=c=blue:s=64x48:r=10',
            '-f', 'lavfi', '-i', 'sine=frequency=440', '-t', '0.3', '-c:v', $codec, '-pix_fmt', 'yuv420p', '-c:a', 'aac', $source]);
        return $source;
    }

    public function testOlderMpeg4VideoGetsCachedH264WithAudioAndKeepsOriginal(): void
    {
        $source = $this->source('mpeg4');
        $hash = hash_file('sha256', $source);
        $service = new VideoPreviewService($this->root . '/cache');
        $preview = $service->forFile($source);
        $this->assertNotSame($source, $preview);
        $this->assertSame($hash, hash_file('sha256', $source));
        $info = json_decode($this->runCommand([dirname($this->ffmpeg) . '/ffprobe', '-v', 'error', '-show_entries', 'stream=codec_type,codec_name,pix_fmt', '-of', 'json', $preview]), true);
        $this->assertSame('h264', $info['streams'][0]['codec_name']);
        $this->assertSame('yuv420p', $info['streams'][0]['pix_fmt']);
        $this->assertSame('aac', $info['streams'][1]['codec_name']);
        touch($preview, 1000000000);
        $this->assertSame($preview, $service->forFile($source));
        clearstatcache(true, $preview);
        $this->assertSame(1000000000, filemtime($preview));
        touch($source, time() + 2);
        $this->assertNotSame($preview, $service->forFile($source));
    }

    public function testCompatibleVideoIsServedWithoutConversion(): void
    {
        $source = $this->source('libx264');
        $service = new VideoPreviewService($this->root . '/cache');
        $this->assertSame($source, $service->forFile($source));
        $this->assertSame($source, $service->forFile($source));
        $this->assertSame([], glob($this->root . '/cache/*.mp4'));
    }

    public function testThumbnailIsASmallCachedJpegEvenForASubsecondVideo(): void
    {
        $source = $this->source('mpeg4');
        $hash = hash_file('sha256', $source);
        $service = new VideoPreviewService($this->root . '/cache');
        $poster = $service->thumbnailForFile($source);
        $info = getimagesize($poster);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertLessThanOrEqual(640, $info[0]);
        $this->assertLessThanOrEqual(360, $info[1]);
        $this->assertSame($hash, hash_file('sha256', $source));
        $this->assertSame([], glob($this->root . '/cache/*.mp4'), 'A thumbnail must not transcode the entire video.');
        touch($poster, 1000000000);
        $this->assertSame($poster, $service->thumbnailForFile($source));
        clearstatcache(true, $poster);
        $this->assertSame(1000000000, filemtime($poster));
    }

    public function testInvalidVideoFailsWithoutPublishingAPreview(): void
    {
        $source = $this->root . '/bad.mp4';
        file_put_contents($source, 'not video');
        try {
            (new VideoPreviewService($this->root . '/cache'))->forFile($source);
            $this->fail('Invalid video must be rejected.');
        } catch (RuntimeException $error) {
            $this->assertSame('Video preview could not be prepared.', $error->getMessage());
            $this->assertSame([], glob($this->root . '/cache/*.mp4'));
        }
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testSlowProbeOutlivesPhpDefaultAndRestoresTheLimit(): void
    {
        $source = $this->source('libx264');
        $probe = $this->root . '/slow-probe';
        file_put_contents($probe, '#!' . PHP_BINARY . "\n<?php sleep(2); echo " . var_export(json_encode([
            'streams' => [['codec_type'=>'video', 'codec_name'=>'h264', 'pix_fmt'=>'yuv420p']],
            'format' => ['format_name'=>'mp4'],
        ]), true) . ';');
        chmod($probe, 0700);
        $previous = (int) ini_get('max_execution_time');
        $previousProbe = getenv('greyhound.ffprobeBinary');
        putenv('greyhound.ffprobeBinary=' . $probe);
        try {
            set_time_limit(1);
            $this->assertSame($source, (new VideoPreviewService($this->root . '/cache'))->forFile($source));
            $this->assertSame(1, (int) ini_get('max_execution_time'));
        } finally {
            set_time_limit($previous);
            putenv($previousProbe === false ? 'greyhound.ffprobeBinary' : 'greyhound.ffprobeBinary=' . $previousProbe);
        }
    }

    public function testProcessDeadlineStillStopsASlowConverter(): void
    {
        $started = microtime(true);
        try {
            (new ReflectionMethod(VideoPreviewService::class, 'run'))->invoke(
                new VideoPreviewService($this->root . '/cache'), [PHP_BINARY, '-r', 'sleep(10);'], 1
            );
            $this->fail('The process must be stopped at its deadline.');
        } catch (RuntimeException $error) {
            $this->assertSame('Video preview preparation timed out.', $error->getMessage());
            $this->assertLessThan(5, microtime(true) - $started);
        }
    }
}
