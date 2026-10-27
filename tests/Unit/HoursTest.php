<?php

namespace Tests\Unit;

use App\Support\Hours;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HoursTest extends TestCase
{
    /**
     * @return array<string, array{string, int|null}>
     */
    public static function typedDurations(): array
    {
        return [
            'whole hours' => ['4', 240],
            'hours suffix' => ['4h', 240],
            'decimal comma' => ['1,5', 90],
            'decimal point' => ['2.25', 135],
            'one decimal digit' => ['0,1', 6],
            'clock' => ['1:30', 90],
            'hours and minutes' => ['1h30', 90],
            'spaces' => [' 1 h 05 ', 65],
            'largest' => ['9999', 599_940],
            'zero' => ['0', null],
            'too large' => ['10000', null],
            'minutes over 59' => ['1:75', null],
            'negative' => ['-2', null],
            'text' => ['duas', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('typedDurations')]
    public function test_typed_durations_are_parsed_to_minutes(string $typed, ?int $minutes): void
    {
        $this->assertSame($minutes, Hours::parse($typed));
    }

    public function test_minutes_are_formatted_as_hours(): void
    {
        $this->assertSame('4h', Hours::format(240));
        $this->assertSame('1h30', Hours::format(90));
        $this->assertSame('2h05', Hours::format(125));
        $this->assertSame('45min', Hours::format(45));
        $this->assertSame('0h', Hours::format(0));
    }
}
