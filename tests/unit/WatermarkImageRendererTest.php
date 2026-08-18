<?php

use App\Services\WatermarkImageRenderer;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class WatermarkImageRendererTest extends CIUnitTestCase
{
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
