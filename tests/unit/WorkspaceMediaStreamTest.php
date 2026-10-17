<?php

use App\Controllers\Web\WorkspaceController;
use App\Libraries\MediaStreamResponse;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

final class WorkspaceMediaStreamTest extends CIUnitTestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'greyhound-stream-');
        file_put_contents($this->path, '0123456789');
        $session = $this->createMock(Session::class);
        $session->expects($this->once())->method('close');
        Services::injectMock('session', $session);
    }

    protected function tearDown(): void
    {
        unlink($this->path);
        Services::resetSingle('session');
        parent::tearDown();
    }

    private function response(string $range = '', string $method = 'GET')
    {
        $request = new IncomingRequest(config('App'), new URI('http://example.com/'), null, new UserAgent());
        $request->setMethod($method);
        $request->setHeader('Range', $range);
        $controller = new WorkspaceController();
        $controller->initController($request, new Response(config('App')), service('logger'));
        return (new ReflectionMethod(WorkspaceController::class, 'inlineMediaResponse'))
            ->invoke($controller, $this->path, 'video/mp4');
    }

    public static function ranges(): array
    {
        return [
            'whole file' => ['', 200, '0123456789', ''],
            'open ended' => ['bytes=0-', 206, '0123456789', 'bytes 0-9/10'],
            'seek' => ['bytes=3-6', 206, '3456', 'bytes 3-6/10'],
            'suffix' => ['bytes=-3', 206, '789', 'bytes 7-9/10'],
            'last byte' => ['bytes=9-', 206, '9', 'bytes 9-9/10'],
            'clamped end' => ['bytes=7-1000', 206, '789', 'bytes 7-9/10'],
            'clamped suffix' => ['bytes=-1000', 206, '0123456789', 'bytes 0-9/10'],
            'empty range ignored' => ['bytes=-', 200, '0123456789', ''],
            'invalid range ignored' => ['other=2-5', 200, '0123456789', ''],
            'multiple ranges ignored' => ['bytes=0-1,4-5', 200, '0123456789', ''],
        ];
    }

    #[DataProvider('ranges')]
    public function testStreamsExactBytesAndHeaders(string $range, int $status, string $expected, string $contentRange): void
    {
        $response = $this->response($range);
        $this->assertInstanceOf(MediaStreamResponse::class, $response);
        $response->buildHeaders();
        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame((string) strlen($expected), $response->getHeaderLine('Content-Length'));
        $this->assertSame($contentRange, $response->getHeaderLine('Content-Range'));
        $this->assertSame('video/mp4', $response->getHeaderLine('Content-Type'));
        $this->assertSame('bytes', $response->getHeaderLine('Accept-Ranges'));
        $this->assertStringStartsWith('inline;', $response->getHeaderLine('Content-Disposition'));
        $this->assertStringContainsString('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertEmpty($response->getBody(), 'The response must not buffer the file.');
        ob_start();
        try {
            $response->sendBody();
            $this->assertSame($expected, ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    public static function invalidRanges(): array
    {
        return [['bytes=10-'], ['bytes=8-2'], ['bytes=-0']];
    }

    #[DataProvider('invalidRanges')]
    public function testUnsatisfiableRangeReturns416(string $range): void
    {
        $response = $this->response($range);
        $this->assertSame(416, $response->getStatusCode());
        $this->assertSame('bytes */10', $response->getHeaderLine('Content-Range'));
    }

    public function testHeadReturnsFullHeadersWithoutFileBytes(): void
    {
        $response = $this->response('bytes=3-6', 'HEAD');
        $response->buildHeaders();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('10', $response->getHeaderLine('Content-Length'));
        $this->assertSame('', $response->getHeaderLine('Content-Range'));
        ob_start();
        try {
            $response->sendBody();
            $this->assertSame('', ob_get_contents());
        } finally {
            ob_end_clean();
        }
    }

    public function testStreamingTemporarilyRemovesPageExecutionLimitAndRestoresIt(): void
    {
        $previous = (int) ini_get('max_execution_time');
        set_time_limit(30);
        $limits = [];
        try {
            $response = $this->response();
            ob_start(static function (string $chunk) use (&$limits): string {
                if ($chunk !== '') $limits[] = (int) ini_get('max_execution_time');
                return '';
            }, 1);
            try { $response->sendBody(); }
            finally { ob_end_clean(); }
            $this->assertSame([0], $limits);
            $this->assertSame(30, (int) ini_get('max_execution_time'));
        } finally { set_time_limit($previous); }
    }

    public function testFileLargerThan128MbStreamsUnder128MbMemoryLimit(): void
    {
        // Sparse fixture reproduces the reported allocation without a large test asset.
        $size = 198792088;
        $file = fopen($this->path, 'r+b');
        ftruncate($file, $size);
        fseek($file, $size - 4);
        fwrite($file, 'TAIL');
        fclose($file);
        clearstatcache(true, $this->path);
        $expectedHash = hash_file('sha256', $this->path);
        $previousLimit = ini_get('memory_limit');
        $received = 0;
        $hash = hash_init('sha256');
        $baseline = memory_get_usage(true);
        $maximum = $baseline;
        ini_set('memory_limit', '128M');
        try {
            $response = $this->response('bytes=0-');
            $response->buildHeaders();
            $this->assertSame((string) $size, $response->getHeaderLine('Content-Length'));
            // Consume output incrementally, as a browser would, rather than collecting it.
            ob_start(static function (string $chunk) use (&$received, $hash, &$maximum): string {
                $received += strlen($chunk);
                hash_update($hash, $chunk);
                $maximum = max($maximum, memory_get_usage(true));
                return '';
            }, 1);
            try {
                $response->sendBody();
            } finally {
                ob_end_flush();
            }
            $this->assertSame($size, $received);
            $this->assertSame($expectedHash, hash_final($hash));
            $this->assertLessThan(16 * 1048576, $maximum - $baseline);
            $this->assertSame('128M', ini_get('memory_limit'));
        } finally {
            ini_set('memory_limit', $previousLimit);
        }
    }
}
