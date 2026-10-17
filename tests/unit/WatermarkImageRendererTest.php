<?php

use App\Services\WatermarkImageRenderer;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class WatermarkImageRendererTest extends CIUnitTestCase
{
    public function testSmallLogoScaleProducesSmallerWatermarkWithoutChangingOriginal(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD is required for watermark rendering.');
        }

        $source = tempnam(sys_get_temp_dir(), 'watermark-source-');
        $logo = tempnam(sys_get_temp_dir(), 'watermark-logo-');
        $output = tempnam(sys_get_temp_dir(), 'watermark-output-');
        try {
            $image = imagecreatetruecolor(1000, 600);
            imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
            imagepng($image, $source);
            imagedestroy($image);
            $mark = imagecreatetruecolor(100, 100);
            imagefill($mark, 0, 0, imagecolorallocate($mark, 255, 0, 0));
            imagepng($mark, $logo);
            imagedestroy($mark);
            $originalHash = hash_file('sha256', $source);
            $widths = [];
            foreach ([10, 50] as $scale) {
                $result = (new WatermarkImageRenderer())->render($source, $logo, $output, 'png', [
                    'resize_percent' => $scale, 'position' => 'center', 'opacity' => 100,
                ]);
                $this->assertTrue($result['written']);
                $rendered = imagecreatefrompng($output);
                $widths[$scale] = 0;
                for ($x = 0; $x < imagesx($rendered); $x++) {
                    if ((imagecolorat($rendered, $x, 300) & 0xFFFFFF) === 0xFF0000) {
                        $widths[$scale]++;
                    }
                }
                imagedestroy($rendered);
            }
            $this->assertGreaterThan(0, $widths[10]);
            $this->assertLessThan(48, $widths[10]);
            $this->assertLessThan($widths[50], $widths[10]);
            $this->assertSame($originalHash, hash_file('sha256', $source));
        } finally {
            foreach ([$source, $logo, $output] as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function testExifOrientationEightRotatesLandscapePixelsToPortrait(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD is required for orientation rendering.');
        }

        $image = imagecreatetruecolor(120, 80);
        $method = new ReflectionMethod(WatermarkImageRenderer::class, 'applyExifOrientation');
        $oriented = $method->invoke(new WatermarkImageRenderer(), $image, 8);

        try {
            $this->assertSame(80, imagesx($oriented));
            $this->assertSame(120, imagesy($oriented));
        } finally {
            imagedestroy($oriented);
        }
    }

    public function testNormalOrientationKeepsOriginalDimensions(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD is required for orientation rendering.');
        }

        $image = imagecreatetruecolor(120, 80);
        $method = new ReflectionMethod(WatermarkImageRenderer::class, 'applyExifOrientation');
        $oriented = $method->invoke(new WatermarkImageRenderer(), $image, 1);

        try {
            $this->assertSame(120, imagesx($oriented));
            $this->assertSame(80, imagesy($oriented));
        } finally {
            imagedestroy($oriented);
        }
    }
}
