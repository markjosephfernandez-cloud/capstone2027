<?php

use App\Services\PassportService;
use CodeIgniter\Test\CIUnitTestCase;

/** @internal */
final class PassportExifTest extends CIUnitTestCase
{
    public function testDateTimeOriginalHasPriority(): void
    {
        $method = new ReflectionMethod(PassportService::class, 'dateTakenFromExif');
        $date = $method->invoke(new PassportService(), [
            'EXIF' => [
                'DateTimeOriginal' => '2026:03:24 16:00:06',
                'DateTimeDigitized' => '2026:03:25 10:00:00',
            ],
            'IFD0' => ['DateTime' => '2026:03:26 10:00:00'],
        ]);

        $this->assertSame('2026-03-24 16:00:06', $date);
    }

    public function testDateTimeDigitizedAndIfdDateAreFallbacks(): void
    {
        $method = new ReflectionMethod(PassportService::class, 'dateTakenFromExif');
        $service = new PassportService();

        $this->assertSame('2025-12-31 23:59:58', $method->invoke($service, [
            'EXIF' => ['DateTimeDigitized' => '2025:12:31 23:59:58'],
        ]));
        $this->assertSame('2024-02-29 08:15:10', $method->invoke($service, [
            'IFD0' => ['DateTime' => '2024:02:29 08:15:10'],
        ]));
    }

    public function testMissingOrInvalidExifDateRemainsNull(): void
    {
        $method = new ReflectionMethod(PassportService::class, 'dateTakenFromExif');
        $service = new PassportService();

        $this->assertNull($method->invoke($service, []));
        $this->assertNull($method->invoke($service, ['EXIF' => ['DateTimeOriginal' => '0000:00:00 00:00:00']]));
        $this->assertNull($method->invoke($service, ['EXIF' => ['DateTimeOriginal' => '2026:02:30 12:00:00']]));
    }
}
