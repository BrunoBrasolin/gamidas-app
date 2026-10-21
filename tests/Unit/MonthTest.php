<?php

namespace Tests\Unit;

use App\Support\Month;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Tests\TestCase;

class MonthTest extends TestCase
{
    protected function tearDown(): void
    {
        Date::setTestNow();

        parent::tearDown();
    }

    public function test_due_days_are_clamped_to_the_last_day_of_the_month(): void
    {
        $this->assertSame('2026-02-28', Month::dueDate(Month::parse('2026-02'), 31)->toDateString());
        $this->assertSame('2028-02-29', Month::dueDate(Month::parse('2028-02'), 30)->toDateString());
        $this->assertSame('2026-04-30', Month::dueDate(Month::parse('2026-04'), 31)->toDateString());
        $this->assertSame('2026-01-31', Month::dueDate(Month::parse('2026-01'), 31)->toDateString());
        $this->assertSame('2026-03-05', Month::dueDate(Month::parse('2026-03'), 5)->toDateString());
    }

    public function test_the_current_month_uses_the_sao_paulo_timezone(): void
    {
        // 23:30 on 31 October in São Paulo is already 1 November in UTC.
        Date::setTestNow(CarbonImmutable::parse('2026-11-01 02:30:00', 'UTC'));

        $this->assertSame('2026-10', Month::key(Month::current()));
    }

    public function test_month_differences(): void
    {
        $this->assertSame(3, Month::diff(Month::parse('2026-11'), Month::parse('2027-02')));
        $this->assertSame(0, Month::diff(Month::parse('2026-11'), Month::parse('2026-11')));
        $this->assertSame(-1, Month::diff(Month::parse('2026-11'), Month::parse('2026-10')));
    }

    public function test_invalid_months_fall_back_to_the_current_month(): void
    {
        Date::setTestNow('2026-10-21 10:00:00');

        $this->assertSame('2026-10', Month::key(Month::fromInput('2026-13')));
        $this->assertSame('2026-10', Month::key(Month::fromInput(['2026-01'])));
        $this->assertSame('2026-10', Month::key(Month::fromInput('1800-01')));
        $this->assertSame('2027-01', Month::key(Month::fromInput('2027-01')));
    }

    public function test_parsing_rejects_malformed_months(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Month::parse('2026-1');
    }
}
