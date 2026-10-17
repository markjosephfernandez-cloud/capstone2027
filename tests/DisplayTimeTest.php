<?php

namespace Tests;

use App\Libraries\DisplayTime;
use CodeIgniter\Test\CIUnitTestCase;

final class DisplayTimeTest extends CIUnitTestCase
{
    public function testUtcDatesConvertToPhilippineTimeAcrossMidnight(): void
    {
        $this->assertSame('Oct 02, 2026 5:00 PM', DisplayTime::format('2026-10-02 09:00:00'));
        $this->assertSame('Oct 03, 2026 1:30 AM', DisplayTime::format('2026-10-02 17:30:00'));
        $this->assertSame('Oct 3', DisplayTime::format('2026-10-02 17:30:00', 'M j'));
    }

    public function testConversionDoesNotDependOnOrChangeTheProcessTimezone(): void
    {
        $original = date_default_timezone_get();
        try {
            foreach (['UTC', 'Asia/Manila', 'America/New_York'] as $timezone) {
                date_default_timezone_set($timezone);
                $this->assertSame('Oct 02, 2026 5:00 PM', DisplayTime::format('2026-10-02 09:00:00'));
                $this->assertSame('Oct 02, 2026 5:00 PM', DisplayTime::format(strtotime('2026-10-02 09:00:00 UTC')));
                $this->assertSame('Oct 02, 2026 5:00 PM', DisplayTime::format('2026-10-02T17:00:00+08:00'));
                $this->assertSame($timezone, date_default_timezone_get());
            }
        } finally { date_default_timezone_set($original); }
    }

    public function testMissingDatesDoNotBecomeTheCurrentTime(): void
    {
        foreach ([null, '', '   ', 'Unknown', 'invalid-date', '0000-00-00 00:00:00'] as $value) {
            $this->assertSame('—', DisplayTime::format($value));
        }
        $this->assertSame('Unavailable', DisplayTime::format(null, 'M j', 'Unavailable'));
    }
}
