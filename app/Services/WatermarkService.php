<?php

namespace App\Services;

use RuntimeException;

/** Generates non-destructive logo-watermarked image derivatives. */
class WatermarkService
{
    private const LOGOS = [
        'FU'  => ['label' => 'Foundation University', 'file' => 'FU.png'],
        'CN'  => ['label' => 'College of Nursing', 'file' => 'CN.png'],
        'CHM' => ['label' => 'College of Hospitality Management', 'file' => 'CHM.png'],
        'CCS' => ['label' => 'College of Computer Studies', 'file' => 'CCS.png'],
        'CBA' => ['label' => 'College of Business Administration', 'file' => 'CBA.png'],
    ];

    /** @return list<array{code:string,label:string}> */
    public function availableLogos(): array
    {
        $logos = [];
        foreach (self::LOGOS as $code => $logo) {
            if (is_file($this->logoDirectory() . DIRECTORY_SEPARATOR . $logo['file'])) {
                $logos[] = ['code' => $code, 'label' => $logo['label']];
            }
        }
        return $logos;
    }

    public function settingsLogoCode(array $settings): string
    {
        $value = strtoupper(trim((string) ($settings['logo_path'] ?? 'FU')));
        $value = pathinfo(str_replace('\\', '/', $value), PATHINFO_FILENAME);
        return isset(self::LOGOS[$value]) ? $value : 'FU';
    }

    public function logoPath(string $code): string
    {
        $code = strtoupper(trim($code));
        if (! isset(self::LOGOS[$code])) {
            throw new RuntimeException('Choose a valid department logo.');
        }
        $path = realpath($this->logoDirectory() . DIRECTORY_SEPARATOR . self::LOGOS[$code]['file']);
        if ($path === false || ! is_file($path)) {
            throw new RuntimeException('The selected department logo is unavailable.');
        }
        return $path;
    }

    /** @param array<string, mixed> $file @param array<string, mixed> $actor @return array{applied: bool, path?: string, reason?: string, logo_code?: string} */
    public function applyForCreativeUpload(array $file, array $actor): array
    {
        $db = db_connect();
        if (! $db->tableExists('watermark_settings') || ! $this->isCreativeDepartment((string) ($actor['department'] ?? ''))) {
            return ['applied' => false, 'reason' => 'This media is not designated for automatic watermarking.'];
        }
        $settings = $db->table('watermark_settings')->orderBy('id', 'ASC')->get(1)->getRowArray() ?? [];
        if (empty($settings['enabled'])) {
            return ['applied' => false, 'reason' => 'Automatic watermarking is disabled.'];
        }
        return $this->generate($file, $actor, $this->settingsLogoCode($settings), $settings, true);
    }

    /** @param array<string, mixed> $file @param array<string, mixed> $actor @return array{applied: bool, path?: string, reason?: string, logo_code?: string} */
    public function createForDownload(array $file, array $actor, string $logoCode): array
    {
        $db = db_connect();
        $settings = $db->tableExists('watermark_settings')
            ? ($db->table('watermark_settings')->orderBy('id', 'ASC')->get(1)->getRowArray() ?? [])
            : [];
        return $this->generate($file, $actor, strtoupper(trim($logoCode)), $settings, false);
    }

    /** @param array<string, mixed> $file @param array<string, mixed> $actor @return array{applied: bool, path?: string, reason?: string, logo_code?: string} */
    public function applyDefaultToExisting(array $file, array $actor): array
    {
        $db = db_connect();
        $settings = $db->tableExists('watermark_settings')
            ? ($db->table('watermark_settings')->orderBy('id', 'ASC')->get(1)->getRowArray() ?? [])
            : [];
        return $this->generate($file, $actor, $this->settingsLogoCode($settings), $settings, true);
    }

    /** @param array<string, mixed> $file @param array<string, mixed> $actor @param array<string, mixed> $settings @return array{applied: bool, path?: string, reason?: string, logo_code?: string} */
    private function generate(array $file, array $actor, string $logoCode, array $settings, bool $persist): array
    {
        try {
            $source = service('storage')->resolveDatabaseFile((string) ($file['filepath'] ?? ''));
            $logoPath = $this->logoPath($logoCode);
        } catch (RuntimeException $exception) {
            return ['applied' => false, 'reason' => $exception->getMessage()];
        }
        $extension = strtolower((string) pathinfo($source, PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return ['applied' => false, 'reason' => 'Only JPEG, PNG, and WebP images can receive a logo watermark.'];
        }
        $scale = max(50, min(200, (int) ($settings['resize_percent'] ?? 100)));
        $opacity = max(30, min(100, (int) ($settings['opacity'] ?? 65)));
        if ($persist) {
            $watermarkDirectory = service('storage')->directory('Watermark', true);
            $destination = $watermarkDirectory . DIRECTORY_SEPARATOR . 'WM_' . (int) $file['id'] . '_' . basename($source);
        } else {
            $tempDirectory = WRITEPATH . 'cache' . DIRECTORY_SEPARATOR . 'watermark_downloads';
            if (! is_dir($tempDirectory) && ! mkdir($tempDirectory, 0775, true) && ! is_dir($tempDirectory)) {
                return ['applied' => false, 'reason' => 'The temporary watermark directory could not be created.'];
            }
            $destination = $tempDirectory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '_' . strtolower($logoCode) . '_' . basename($source);
        }

        $renderSettings = $settings + ['resize_percent' => $scale, 'opacity' => $opacity];
        $render = extension_loaded('gd')
            ? (new WatermarkImageRenderer())->render($source, $logoPath, $destination, $extension, $renderSettings)
            : $this->renderWithCli($source, $logoPath, $destination, $extension, $renderSettings);
        if (! ($render['written'] ?? false) || ! is_file($destination)) {
            return ['applied' => false, 'reason' => (string) ($render['reason'] ?? 'The logo-watermarked image could not be saved.')];
        }

        if ($persist) {
            $db = db_connect();
            $snapshot = json_encode(['logo_code' => $logoCode, 'position' => $settings['position'] ?? 'bottom_right', 'opacity' => $opacity, 'scale' => $scale], JSON_UNESCAPED_SLASHES);
            $existing = $db->table('file_watermarks')->where('media_id', (int) $file['id'])->get()->getRowArray();
            $payload = ['media_id' => (int) $file['id'], 'watermarked_path' => $destination, 'applied_by' => (string) ($actor['employee_id'] ?? ''), 'applied_at' => gmdate('Y-m-d H:i:s'), 'settings_snapshot' => $snapshot];
            if ($existing === null) {
                $db->table('file_watermarks')->insert($payload);
            } else {
                $oldPath = (string) ($existing['watermarked_path'] ?? '');
                $db->table('file_watermarks')->where('id', (int) $existing['id'])->update($payload);
                if ($oldPath !== '' && $oldPath !== $destination && is_file($oldPath)) {
                    @unlink($oldPath);
                }
            }
        }

        return ['applied' => true, 'path' => $destination, 'logo_code' => $logoCode];
    }

    /** @param array<string, mixed> $settings @return array{written: bool, reason?: string} */
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

    private function isCreativeDepartment(string $department): bool
    {
        return str_contains(mb_strtolower($department), 'creative');
    }
}
