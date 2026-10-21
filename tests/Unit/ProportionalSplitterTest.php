<?php

namespace Tests\Unit;

use App\Services\ProportionalSplitter;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProportionalSplitterTest extends TestCase
{
    private ProportionalSplitter $splitter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->splitter = new ProportionalSplitter;
    }

    public function test_salaries_of_8000_and_2000_split_80_20(): void
    {
        $shares = $this->splitter->split(100_000, [1 => 800_000, 2 => 200_000]);

        $this->assertSame([1 => 80_000, 2 => 20_000], $shares);
    }

    public function test_percentages_follow_the_configured_salaries(): void
    {
        $this->assertSame([1 => 8_000, 2 => 2_000], $this->splitter->basisPoints([1 => 800_000, 2 => 200_000]));
        $this->assertSame([1 => 3_334, 2 => 3_333, 3 => 3_333], $this->splitter->basisPoints([1 => 1, 2 => 1, 3 => 1]));
    }

    /**
     * @param  array<int, int>  $salaries
     */
    #[DataProvider('splits')]
    public function test_shares_always_add_up_to_the_original_amount(int $amount, array $salaries): void
    {
        $shares = $this->splitter->split($amount, $salaries);

        $this->assertSame($amount, array_sum($shares));
        $this->assertSame(array_keys($salaries), array_keys($shares));

        foreach ($shares as $share) {
            $this->assertGreaterThanOrEqual(0, $share);
        }
    }

    /**
     * @return iterable<string, array{int, array<int, int>}>
     */
    public static function splits(): iterable
    {
        yield 'one cent' => [1, [1 => 800_000, 2 => 200_000]];
        yield 'odd amount 80/20' => [33_333, [1 => 800_000, 2 => 200_000]];
        yield 'thirds' => [10_000, [1 => 100, 2 => 100, 3 => 100]];
        yield 'irregular salaries' => [123_457, [1 => 345_678, 2 => 123_456]];
        yield 'zero amount' => [0, [1 => 800_000, 2 => 200_000]];
        yield 'maximum values' => [999_999_999, [1 => 99_999_999, 2 => 99_999_998]];

        mt_srand(2026);

        for ($i = 0; $i < 50; $i++) {
            yield "random #{$i}" => [mt_rand(0, 99_999_999), [1 => mt_rand(0, 2_000_000), 2 => mt_rand(0, 2_000_000), 3 => mt_rand(0, 2_000_000)]];
        }
    }

    public function test_rounding_remainder_is_assigned_deterministically(): void
    {
        // R$ 0,01 at 80/20: the larger remainder (0.8) gets the cent.
        $this->assertSame([1 => 1, 2 => 0], $this->splitter->split(1, [1 => 800_000, 2 => 200_000]));

        // Equal remainders: the larger salary wins, then input order.
        $this->assertSame([1 => 0, 2 => 1], $this->splitter->split(1, [1 => 100, 2 => 300]));
        $this->assertSame([1 => 1, 2 => 0], $this->splitter->split(1, [1 => 500, 2 => 500]));
        $this->assertSame([1 => 34, 2 => 33, 3 => 33], $this->splitter->split(100, [1 => 1, 2 => 1, 3 => 1]));

        // Same inputs always give the same result.
        $this->assertSame(
            $this->splitter->split(77_777, [5 => 123_456, 9 => 654_321]),
            $this->splitter->split(77_777, [5 => 123_456, 9 => 654_321]),
        );
    }

    public function test_zero_combined_salaries_split_equally_instead_of_dividing_by_zero(): void
    {
        $this->assertSame([1 => 5_001, 2 => 5_000], $this->splitter->split(10_001, [1 => 0, 2 => 0]));
    }

    public function test_a_member_without_salary_owes_nothing_when_others_earn(): void
    {
        $this->assertSame([1 => 10_000, 2 => 0], $this->splitter->split(10_000, [1 => 500_000, 2 => 0]));
    }

    public function test_invalid_input_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->splitter->split(-1, [1 => 100]);
    }

    public function test_at_least_one_participant_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->splitter->split(100, []);
    }
}
