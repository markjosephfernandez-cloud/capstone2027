<?php

use App\Services\ImageSimilarityService;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class ImageSimilarityServiceTest extends CIUnitTestCase
{
    public function testSimilarityUsesPerceptualHashDistance(): void
    {
        $service = new ImageSimilarityService(db_connect());

        $this->assertSame(100.0, $service->similarity('0123456789abcdef', '0123456789abcdef'));
        $this->assertSame(98.44, $service->similarity('0000000000000000', '0000000000000001'));
        $this->assertSame(0.0, $service->similarity('0000000000000000', 'ffffffffffffffff'));
        $this->assertSame(0.0, $service->similarity('not-a-hash', 'ffffffffffffffff'));
    }

    public function testResizedCopyProducesSameFingerprint(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD is required for perceptual image fingerprinting.');
        }

        $smallPath = tempnam(sys_get_temp_dir(), 'similarity-small-');
        $largePath = tempnam(sys_get_temp_dir(), 'similarity-large-');
        $small = imagecreatetruecolor(90, 80);
        $large = imagecreatetruecolor(180, 160);
        $this->drawPattern($small, 90, 80);
        $this->drawPattern($large, 180, 160);
        imagepng($small, $smallPath);
        imagepng($large, $largePath);
        imagedestroy($small);
        imagedestroy($large);

        try {
            $service = new ImageSimilarityService(db_connect());
            $smallFingerprint = $service->fingerprint($smallPath, 'image/png');
            $largeFingerprint = $service->fingerprint($largePath, 'image/png');

            $this->assertNotNull($smallFingerprint);
            $this->assertNotNull($largeFingerprint);
            $this->assertGreaterThanOrEqual(ImageSimilarityService::MATCH_THRESHOLD, $service->similarity($smallFingerprint, $largeFingerprint));
        } finally {
            @unlink($smallPath);
            @unlink($largePath);
        }
    }

    /** @param GdImage|resource $image */
    private function drawPattern($image, int $width, int $height): void
    {
        $dark = imagecolorallocate($image, 25, 35, 55);
        $light = imagecolorallocate($image, 240, 190, 55);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $dark);
        imagefilledellipse($image, (int) ($width * .35), (int) ($height * .5), (int) ($width * .45), (int) ($height * .65), $light);
        imagefilledrectangle($image, (int) ($width * .58), (int) ($height * .18), (int) ($width * .88), (int) ($height * .78), $light);
    }
}
