<?php

namespace Tests\Unit;

use App\Support\Holidays;
use Tests\TestCase;

class HolidaysTest extends TestCase
{
    public function test_fixed_and_movable_national_holidays_are_known(): void
    {
        $this->assertSame('Nossa Senhora Aparecida', Holidays::name('2026-10-12'));
        $this->assertSame('Natal', Holidays::name('2026-12-25'));
        $this->assertSame('Sexta-feira Santa', Holidays::name('2026-04-03'));
        $this->assertSame('Sexta-feira Santa', Holidays::name('2025-04-18'));
        $this->assertNull(Holidays::name('2026-10-13'));
    }

    public function test_consciencia_negra_is_national_only_since_2024(): void
    {
        $this->assertNull(Holidays::name('2023-11-20'));
        $this->assertSame('Dia Nacional de Zumbi e da Consciência Negra', Holidays::name('2024-11-20'));
    }

    public function test_business_days_exclude_weekends_and_holidays(): void
    {
        $this->assertTrue(Holidays::isBusinessDay('2026-10-13'));
        $this->assertFalse(Holidays::isBusinessDay('2026-10-12'));
        $this->assertFalse(Holidays::isBusinessDay('2026-10-17'));
        $this->assertFalse(Holidays::isBusinessDay('2026-10-18'));
    }
}
