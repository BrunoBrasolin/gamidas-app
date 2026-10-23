<?php

namespace Tests\Unit;

use App\Support\Day;
use Illuminate\Support\Facades\Date;
use Tests\TestCase;

class DayTest extends TestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_only_real_dates_in_range_are_parsed(): void
    {
        $this->assertSame('2028-02-29', Day::tryParse('2028-02-29')?->toDateString());
        $this->assertNull(Day::tryParse('2026-02-29'));
        $this->assertNull(Day::tryParse('2026-13-01'));
        $this->assertNull(Day::tryParse('21/10/2026'));
        $this->assertNull(Day::tryParse('1999-12-31'));
        $this->assertNull(Day::tryParse(['2026-10-21']));
    }

    public function test_today_is_the_local_calendar_day(): void
    {
        // 23:30 in São Paulo on the 21st is 02:30 UTC on the 22nd.
        Date::setTestNow('2026-10-22 02:30:00 UTC');

        $this->assertSame('2026-10-21', Day::today()->toDateString());
        $this->assertSame('2026-10-21', Day::fromInput('invalid')->toDateString());
    }

    public function test_weeks_start_on_monday(): void
    {
        $this->assertSame('2026-10-19', Day::startOfWeek(Day::fromInput('2026-10-25'))->toDateString());
        $this->assertSame('2026-10-19', Day::startOfWeek(Day::fromInput('2026-10-19'))->toDateString());
        $this->assertSame(['2026-12-31', '2027-01-01'], Day::range(Day::fromInput('2026-12-31'), Day::fromInput('2027-01-01')));
    }
}
