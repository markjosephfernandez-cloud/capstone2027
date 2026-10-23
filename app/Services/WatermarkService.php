<?php

namespace App\Services;

use CodeIgniter\HTTP\Files\UploadedFile;
use RuntimeException;

/** Generates temporary logo-watermarked images for previews and downloads. */
class WatermarkService
{
    public function __construct(private readonly string $uploadedLogoDirectory = WRITEPATH . 'uploads/watermark-logos')
    {
    }

    private const LOGOS = [
        'FU'  => ['label' => 'Foundation University', 'file' => 'FU.png'],
        'CN'  => ['label' => 'College of Nursing', 'file' => 'CN.png'],
        'CHM' => ['label' => 'College of Hospitality Management', 'file' => 'CHM.png'],
        'CCS' => ['label' => 'College of Computer Studies', 'file' => 'CCS.png'],
        'CBA' => ['label' => 'College of Business Administration', 'file' => 'CBA.png'],
    ];

    /** @return list<array{code:string,label:string,name?:string}> */
    public function availableLogos(): array
    {
        $logos = [];
        foreach (self::LOGOS as $code => $logo) {
            if (is_file($this->logoDirectory() . DIRECTORY_SEPARATOR . $logo['file'])) {
                $logos[] = ['code' => $code, 'label' => $logo['label']];
            }
        }
        return array_merge($logos, array_values($this->uploadedLogos()));
    }

    public function settingsLogoCode(array $settings): string
    {
        $value = strtoupper(trim((string) ($settings['logo_path'] ?? 'FU')));
        $value = pathinfo(str_replace('\\', '/', $value), PATHINFO_FILENAME);
        return isset(self::LOGOS[$value]) || isset($this->uploadedLogos()[$value]) ? $value : 'FU';
    }

    public function logoPath(string $code): string
    {
        $code = strtoupper(trim($code));
        if (isset(self::LOGOS[$code])) {
            $filename = $this->logoDirectory() . DIRECTORY_SEPARATOR . self::LOGOS[$code]['file'];
        } elseif (isset($this->uploadedLogos()[$code])) {
            $filename = $this->uploadedLogoDirectory . DIRECTORY_SEPARATOR . $code . DIRECTORY_SEPARATOR . 'logo.png';
        } else {
            throw new RuntimeException('Choose a valid department logo.');
        }
        $path = realpath($filename);
        if ($path === false || ! is_file($path)) {
            throw new RuntimeException('The selected department logo is unavailable.');
        }
        return $path;
    }

    /** @return array{code:string,label:string,name:string} */
    public function addLogo(UploadedFile $upload, string $name): array
    {
        $name = trim($name);
        if ($name === '' || ! mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 60 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new RuntimeException('Enter a logo name between 1 and 60 characters.');
        }
        if (! $upload->isValid() || $upload->hasMoved()) {
            throw new RuntimeException('Choose a logo image and try uploading it again.');
        }
        if ($upload->getSize() > 5 * 1024 * 1024) {
            throw new RuntimeException('Choose a logo image no larger than 5 MB.');
        }
        $source = $upload->getTempName();
        $info = @getimagesize($source);
        $loader = match ($info['mime'] ?? '') {
            'image/png' => 'imagecreatefrompng',
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/webp' => 'imagecreatefromwebp',
            default => '',
        };
        if ($loader === '') {
            throw new RuntimeException('Upload a valid PNG, JPG, or WebP logo image.');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > 2048 || $info[1] > 2048) {
            throw new RuntimeException('Choose a logo image no larger than 2048 × 2048 pixels.');
        }
        if (! function_exists($loader) || ! function_exists('imagepng')) {
            throw new RuntimeException('Logo uploads require PHP GD support for this image format.');
        }
        $image = @$loader($source);
        if ($image === false) {
            throw new RuntimeException('The logo image could not be read. Choose another image.');
        }
        $directory = null;
        try {
            if (! is_dir($this->uploadedLogoDirectory) && ! @mkdir($this->uploadedLogoDirectory, 0775, true) && ! is_dir($this->uploadedLogoDirectory)) {
                throw new RuntimeException('The logo upload directory could not be created.');
            }
            $code = 'CUSTOM-' . strtoupper(bin2hex(random_bytes(8)));
            $target = $this->uploadedLogoDirectory . DIRECTORY_SEPARATOR . $code;
            if (! @mkdir($target, 0775)) {
                throw new RuntimeException('The logo could not be stored. Please try again.');
            }
            $directory = $target;
            imagealphablending($image, false);
            imagesavealpha($image, true);
            if (! imagepng($image, $directory . '/logo.png', 6)) {
                throw new RuntimeException('The logo image could not be saved.');
            }
            $logo = ['code' => $code, 'label' => $name, 'name' => $name];
            if (file_put_contents($directory . '/metadata.json', json_encode($logo, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                throw new RuntimeException('The logo name could not be saved.');
            }
            return $logo;
        } catch (\Throwable $error) {
            if ($directory !== null) {
                @unlink($directory . '/logo.png');
                @unlink($directory . '/metadata.json');
                @rmdir($directory);
            }
            throw $error;
        } finally {
            imagedestroy($image);
        }
    }

    /** @return array<string,array{code:string,label:string,name:string}> */
    private function uploadedLogos(): array
    {
        $logos = [];
        foreach (glob($this->uploadedLogoDirectory . '/CUSTOM-*/metadata.json') ?: [] as $metadata) {
            $code = basename(dirname($metadata));
            if (! preg_match('/^CUSTOM-[A-F0-9]{16}$/D', $code) || ! is_file(dirname($metadata) . '/logo.png')) {
                continue;
            }
            $logo = json_decode((string) @file_get_contents($metadata), true);
            if (! is_array($logo) || ! is_string($logo['label'] ?? null) || trim($logo['label']) === '') {
                continue;
            }
            $logos[$code] = ['code' => $code, 'label' => $logo['label'], 'name' => $logo['label']];
        }
        uasort($logos, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']));
        return $logos;
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed> $settings
     * @return array{applied: bool, path?: string, reason?: string, logo_code?: string}
     */
    public function createForDownload(array $file, string $logoCode, array $settings = []): array
    {
        return $this->generate($file, strtoupper(trim($logoCode)), $settings);
    }

    /** The caller must resolve this path through ServerLibraryService first. */
    public function createForServerDownload(string $source, string $logoCode, array $settings = []): array
    {
        return $this->generate([], strtoupper(trim($logoCode)), $settings, $source);
    }

    /**
     * @param array<string, mixed> $file
     * @param array<string, mixed> $settings
     * @return array{applied: bool, path?: string, reason?: string, logo_code?: string}
     */
    private function generate(array $file, string $logoCode, array $settings, ?string $source = null): array
    {
        try {
            $source ??= service('storage')->resolveDatabaseFile((string) ($file['filepath'] ?? ''));
            $logoPath = $this->logoPath($logoCode);
        } catch (RuntimeException $exception) {
            return ['applied' => false, 'reason' => $exception->getMessage()];
        }
        $extension = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return ['applied' => false, 'reason' => 'Only JPEG, PNG, and WebP images can receive a logo watermark.'];
        }
        $scale = max(10, min(200, (int) ($settings['resize_percent'] ?? 100)));
        $opacity = max(30, min(100, (int) ($settings['opacity'] ?? 65)));
        $tempDirectory = WRITEPATH . 'cache' . DIRECTORY_SEPARATOR . 'watermark_downloads';
        if (! is_dir($tempDirectory) && ! mkdir($tempDirectory, 0775, true) && ! is_dir($tempDirectory)) {
            return ['applied' => false, 'reason' => 'The temporary watermark directory could not be created.'];
        }
        $destination = $tempDirectory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '_' . strtolower($logoCode) . '_' . basename($source);

        $renderSettings = $settings + ['resize_percent' => $scale, 'opacity' => $opacity];
        $render = extension_loaded('gd')
            ? (new WatermarkImageRenderer())->render($source, $logoPath, $destination, $extension, $renderSettings)
            : $this->renderWithCli($source, $logoPath, $destination, $extension, $renderSettings);
        if (! ($render['written'] ?? false) || ! is_file($destination)) {
            return ['applied' => false, 'reason' => (string) ($render['reason'] ?? 'The logo-watermarked image could not be saved.')];
        }

        return ['applied' => true, 'path' => $destination, 'logo_code' => $logoCode];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{written: bool, reason?: string}
     */
    private function renderWithCli(string $source, string $logoPath, string $destination, string $extension, array $settings): array
    {
        if (! function_exists('proc_open')) {
            return ['written' => false, 'reason' => 'The server cannot start the watermark image renderer.'];
        }
        $worker = ROOTPATH . 'app' . DIRECTORY_SEPARATOR . 'Commands' . DIRECTORY_SEPARATOR . 'watermark-render-worker.php';
        $pipes = [];
        $process = proc_open([PHP_BINARY, $worker], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOTPATH);
        if (! is_resource($process)) {
            return ['written' => false, 'reason' => 'The watermark image renderer could not be started.'];
        }
        fwrite($pipes[0], json_encode(['source' => $source, 'logo' => $logoPath, 'destination' => $destination, 'extension' => $extension, 'settings' => $settings], JSON_UNESCAPED_SLASHES));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        $result = json_decode($output, true);
        if ($exitCode !== 0 || ! is_array($result)) {
            return ['written' => false, 'reason' => trim($error) !== '' ? 'The watermark renderer failed to process this image.' : 'The watermark renderer returned an invalid response.'];
        }
        return $result;
    }

    private function logoDirectory(): string
    {
        return ROOTPATH . 'Department Logo';
    }

}
