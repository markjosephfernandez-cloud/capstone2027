<?php

namespace App\Services;

/** Performs the GD-only image composition used by WatermarkService. */
final class WatermarkImageRenderer
{
    /** @param array<string, mixed> $settings @return array{written: bool, reason?: string} */
    public function render(string $source, string $logoPath, string $destination, string $extension, array $settings): array
    {
        if (! extension_loaded('gd')) {
            return ['written' => false, 'reason' => 'The PHP GD image extension is unavailable.'];
        }

        $loader = match ($extension) {'jpg', 'jpeg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'webp' => 'imagecreatefromwebp'};
        $writer = match ($extension) {'jpg', 'jpeg' => 'imagejpeg', 'png' => 'imagepng', 'webp' => 'imagewebp'};
        if (! function_exists($loader) || ! function_exists($writer) || ! function_exists('imagecreatefrompng')) {
            return ['written' => false, 'reason' => 'This image format is not supported by the local GD installation.'];
        }

        $image = @$loader($source);
        $logo = @imagecreatefrompng($logoPath);
        if ($image === false || $logo === false) {
            if ($image !== false) {
                imagedestroy($image);
            }
            return ['written' => false, 'reason' => 'The source image or selected logo could not be opened.'];
        }
        $image = $this->applyExifOrientation($image, $this->exifOrientation($source, $extension));

        $width = imagesx($image);
        $height = imagesy($image);
        $logoWidth = imagesx($logo);
        $logoHeight = imagesy($logo);
        $scale = max(10, min(200, (int) ($settings['resize_percent'] ?? 100)));
        $targetWidth = max(1, min(900, (int) round($width * 0.18 * $scale / 100)));
        $targetHeight = max(1, (int) round($targetWidth * $logoHeight / max(1, $logoWidth)));
        if ($targetHeight > $height * 0.35) {
            $targetHeight = max(32, (int) round($height * 0.35));
            $targetWidth = max(1, (int) round($targetHeight * $logoWidth / max(1, $logoHeight)));
        }

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
        imagefill($resized, 0, 0, $transparent);
        imagecopyresampled($resized, $logo, 0, 0, 0, 0, $targetWidth, $targetHeight, $logoWidth, $logoHeight);
        imagedestroy($logo);

        $opacity = max(30, min(100, (int) ($settings['opacity'] ?? 65)));
        if ($opacity < 100) {
            $this->applyOpacity($resized, $opacity);
        }
        $padding = max(10, (int) round(min($width, $height) * 0.02));
        [$x, $y] = $this->position((string) ($settings['position'] ?? 'bottom_right'), $width, $height, $targetWidth, $targetHeight, $padding);
        imagealphablending($image, true);
        imagecopy($image, $resized, $x, $y, 0, 0, $targetWidth, $targetHeight);
        imagedestroy($resized);

        $written = match ($extension) {
            'jpg', 'jpeg' => $writer($image, $destination, 90),
            'png'         => $writer($image, $destination, 6),
            default       => $writer($image, $destination, 90),
        };
        imagedestroy($image);

        return $written && is_file($destination)
            ? ['written' => true]
            : ['written' => false, 'reason' => 'The logo-watermarked image could not be saved.'];
    }

    private function exifOrientation(string $source, string $extension): int
    {
        if (! in_array($extension, ['jpg', 'jpeg'], true) || ! function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($source, 'IFD0', true);
        if (! is_array($exif)) {
            return 1;
        }

        return max(1, min(8, (int) ($exif['IFD0']['Orientation'] ?? $exif['Orientation'] ?? 1)));
    }

    private function applyExifOrientation(\GdImage $image, int $orientation): \GdImage
    {
        if ($orientation === 2) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 3) {
            $image = $this->rotate($image, 180);
        } elseif ($orientation === 4) {
            imageflip($image, IMG_FLIP_VERTICAL);
        } elseif ($orientation === 5) {
            $image = $this->rotate($image, 90);
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 6) {
            $image = $this->rotate($image, -90);
        } elseif ($orientation === 7) {
            $image = $this->rotate($image, -90);
            imageflip($image, IMG_FLIP_HORIZONTAL);
        } elseif ($orientation === 8) {
            $image = $this->rotate($image, 90);
        }

        return $image;
    }

    private function rotate(\GdImage $image, float $degrees): \GdImage
    {
        $rotated = imagerotate($image, $degrees, 0);
        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);
        return $rotated;
    }

    private function applyOpacity(\GdImage $image, int $opacity): void
    {
        imagealphablending($image, false);
        for ($y = 0, $height = imagesy($image); $y < $height; ++$y) {
            for ($x = 0, $width = imagesx($image); $x < $width; ++$x) {
                $rgba = imagecolorat($image, $x, $y);
                $alpha = ($rgba >> 24) & 0x7F;
                if ($alpha >= 127) {
                    continue;
                }
                $newAlpha = 127 - (int) round((127 - $alpha) * $opacity / 100);
                $color = imagecolorallocatealpha($image, ($rgba >> 16) & 0xFF, ($rgba >> 8) & 0xFF, $rgba & 0xFF, max(0, min(127, $newAlpha)));
                imagesetpixel($image, $x, $y, $color);
            }
        }
        imagesavealpha($image, true);
    }

    /** @return array{0:int, 1:int} */
    private function position(string $position, int $width, int $height, int $markWidth, int $markHeight, int $padding): array
    {
        return match ($position) {
            'top_left'    => [$padding, $padding],
            'top_right'   => [max($padding, $width - $markWidth - $padding), $padding],
            'bottom_left' => [$padding, max($padding, $height - $markHeight - $padding)],
            'center'      => [max(0, (int) (($width - $markWidth) / 2)), max(0, (int) (($height - $markHeight) / 2))],
            default       => [max($padding, $width - $markWidth - $padding), max($padding, $height - $markHeight - $padding)],
        };
    }
}
