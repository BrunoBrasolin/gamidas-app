<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_decimal_strings_convert_to_cents_without_floating_point(): void
    {
        $this->assertSame(123_456, Money::toCents('1234.56'));
        $this->assertSame(50, Money::toCents('0.5'));
        $this->assertSame(1_000, Money::toCents('10'));
        $this->assertSame(1, Money::toCents('0.01'));
        $this->assertSame(0, Money::toCents('0'));
        $this->assertSame(30, Money::toCents('0.30'));
        $this->assertSame(999_999_999, Money::toCents('9999999.99'));
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts_are_rejected(string $amount): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::toCents($amount);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAmounts(): iterable
    {
        yield 'comma decimal' => ['1,50'];
        yield 'negative' => ['-1.00'];
        yield 'three decimals' => ['1.234'];
        yield 'thousands separator' => ['1.234,56'];
        yield 'text' => ['abc'];
        yield 'empty' => [''];
        yield 'scientific' => ['1e5'];
    }

    public function test_cents_format_as_brazilian_real(): void
    {
        $this->assertSame('R$ 1.234,56', Money::format(123_456));
        $this->assertSame('R$ 0,05', Money::format(5));
        $this->assertSame('R$ 1.000.000,00', Money::format(100_000_000));
        $this->assertSame('-R$ 10,00', Money::format(-1_000));
    }

    public function test_cents_convert_back_to_decimal_strings(): void
    {
        $this->assertSame('1234.50', Money::toDecimal(123_450));
        $this->assertSame('0.07', Money::toDecimal(7));
        $this->assertSame('-3.00', Money::toDecimal(-300));
    }
}
