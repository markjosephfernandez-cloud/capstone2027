<?php

use App\Controllers\Web\AddonsController;
use App\Services\AuditService;
use App\Services\WatermarkImageRenderer;
use App\Services\WatermarkService;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\URI;
use CodeIgniter\HTTP\UserAgent;
use CodeIgniter\Session\Session;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;
use PHPUnit\Framework\Attributes\DataProvider;

final class WatermarkLogoUploadTest extends CIUnitTestCase
{
    private string $directory;
    private WatermarkService $watermarks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/greyhound-logo-test-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->watermarks = new WatermarkService($this->directory . '/logos');
        Services::injectMock('watermarking', $this->watermarks);
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function logo(string $format = 'png', int $width = 20): UploadedFile
    {
        if (! extension_loaded('gd')) $this->markTestSkipped('GD is required for logo uploads.');
        $path = $this->directory . '/upload.' . $format;
        $image = imagecreatetruecolor($width, 20);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledrectangle($image, 5, 5, 14, 14, imagecolorallocatealpha($image, 255, 0, 0, 0));
        ('image' . $format)($image, $path);
        imagedestroy($image);
        return $this->uploadForPath($path);
    }

    private function uploadForPath(string $path): UploadedFile
    {
        $upload = $this->getMockBuilder(UploadedFile::class)->setConstructorArgs([$path, basename($path), null, filesize($path), UPLOAD_ERR_OK])->onlyMethods(['isValid'])->getMock();
        $upload->method('isValid')->willReturn(true);
        return $upload;
    }

    public function testUploadedLogoPersistsAndPreservesTransparencyForRendering(): void
    {
        $logo = $this->watermarks->addLogo($this->logo(), 'Alumni Association');
        $reloaded = new WatermarkService($this->directory . '/logos');
        $this->assertContains($logo, $reloaded->availableLogos());
        $this->assertSame($logo['code'], $reloaded->settingsLogoCode(['logo_path' => $logo['code']]));
        $this->assertSame('FU', $reloaded->settingsLogoCode(['logo_path' => 'FU.png']));
        $path = $reloaded->logoPath($logo['code']);
        $image = imagecreatefrompng($path);
        $this->assertSame(127, (imagecolorat($image, 0, 0) >> 24) & 127);
        $this->assertSame(0, (imagecolorat($image, 10, 10) >> 24) & 127);
        imagedestroy($image);
        $result = (new WatermarkImageRenderer())->render($this->directory . '/upload.png', $path, $this->directory . '/result.png', 'png', ['resize_percent' => 10]);
        $this->assertTrue($result['written']);
        $this->assertFileExists($this->directory . '/result.png');
    }

    public function testJpegAndWebpAreStoredAsPngAndNamesDoNotControlPaths(): void
    {
        foreach (['jpeg', 'webp'] as $format) {
            if (! function_exists('image' . $format)) continue;
            $logo = $this->watermarks->addLogo($this->logo($format), '../Custom <Logo>');
            $path = $this->watermarks->logoPath($logo['code']);
            $this->assertMatchesRegularExpression('/^CUSTOM-[A-F0-9]{16}$/', $logo['code']);
            $this->assertStringStartsWith(realpath($this->directory . '/logos') . '/', $path);
            $this->assertSame('image/png', getimagesize($path)['mime']);
        }
    }

    public function testInvalidImageIsRejectedEvenWithPngFilename(): void
    {
        $upload = $this->logo();
        file_put_contents($upload->getTempName(), '<?php echo "not an image";');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('valid PNG, JPG, or WebP');
        $this->watermarks->addLogo($upload, 'Invalid');
    }

    public function testOversizedDimensionsAreRejected(): void
    {
        $upload = $this->logo('png', 2049);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2048');
        $this->watermarks->addLogo($upload, 'Too wide');
    }

    public function testOversizedFileIsRejected(): void
    {
        $upload = $this->logo();
        file_put_contents($upload->getTempName(), str_repeat('x', 5 * 1024 * 1024 + 1));
        clearstatcache();
        $upload = $this->uploadForPath($upload->getTempName());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('5 MB');
        $this->watermarks->addLogo($upload, 'Too large');
    }

    public function testBlankNameIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('logo name');
        $this->watermarks->addLogo($this->logo(), '   ');
    }

    public function testPathTraversalIsNotAValidLogoCode(): void
    {
        $this->expectException(RuntimeException::class);
        $this->watermarks->logoPath('../../.env');
    }

    public function testFailedUploadIsRejected(): void
    {
        $upload = new UploadedFile('', 'logo.png', 'image/png', 0, UPLOAD_ERR_NO_FILE);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Choose a logo image');
        $this->watermarks->addLogo($upload, 'Missing image');
    }

    private function actor(string $role): Session
    {
        $actor = ['isLoggedIn' => true, 'employee_id' => 1, 'name' => 'Test Admin', 'role' => $role];
        $session = $this->createMock(Session::class);
        $session->method('get')->willReturnCallback(static fn ($key = null) => $key === null ? $actor : ($actor[$key] ?? null));
        Services::injectMock('session', $session);
        return $session;
    }

    public function testOnlyAdminsCanUploadLogos(): void
    {
        foreach (['staff', 'writer', 'department'] as $role) {
            $this->actor($role);
            $request = $this->getMockBuilder(IncomingRequest::class)->setConstructorArgs([config('App'), new URI('http://example.com/'), null, new UserAgent()])->onlyMethods(['getFile'])->getMock();
            $request->expects($this->never())->method('getFile');
            $controller = new AddonsController();
            $controller->initController($request, new Response(config('App')), service('logger'));
            $this->assertSame(403, $controller->uploadWatermarkLogo()->getStatusCode());
        }
    }

    public static function uploadRoles(): array
    {
        return [['admin'], ['super_admin']];
    }

    #[DataProvider('uploadRoles')]
    public function testAdminUploadKeepsFolderAndSelectsNewLogo(string $role): void
    {
        $session = $this->actor($role);
        $flash = [];
        $session->method('setFlashdata')->willReturnCallback(static function ($key, $value) use (&$flash) { $flash[$key] = $value; });
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('record');
        Services::injectMock('audit', $audit);
        $upload = $this->logo();
        $request = $this->getMockBuilder(IncomingRequest::class)->setConstructorArgs([config('App'), new URI('http://example.com/'), null, new UserAgent()])->onlyMethods(['getFile'])->getMock();
        $request->method('getFile')->with('logo_file')->willReturn($upload);
        $request->setGlobal('post', ['logo_name' => 'New department', 'folder' => '148', 'page' => '2']);
        $controller = new AddonsController();
        $controller->initController($request, new Response(config('App')), service('logger'));
        $response = $controller->uploadWatermarkLogo();
        $this->assertSame(site_url('workspace/watermarking') . '?folder=148&page=2', $response->getHeaderLine('Location'));
        $this->assertFileExists($this->watermarks->logoPath($flash['watermark_logo_added']));
        $this->assertArrayHasKey('success', $flash);
    }
}
