<?php

namespace Tests\Unit;

use App\Enums\PurchaseMode;
use App\Services\InstallmentPlanner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class InstallmentPlannerTest extends TestCase
{
    public function test_a_1200_purchase_in_six_installments_is_six_charges_of_200(): void
    {
        $plan = (new InstallmentPlanner)->plan(PurchaseMode::Installments, 120_000, 6);

        $this->assertSame([20_000, 20_000, 20_000, 20_000, 20_000, 20_000], $plan);
    }

    public function test_the_final_installment_absorbs_the_rounding_difference(): void
    {
        $planner = new InstallmentPlanner;

        $this->assertSame([3_333, 3_333, 3_334], $planner->plan(PurchaseMode::Installments, 10_000, 3));
        $this->assertSame([14_285, 14_285, 14_285, 14_285, 14_285, 14_285, 14_290], $planner->plan(PurchaseMode::Installments, 100_000, 7));

        foreach ([[1, 1], [99_999, 12], [123_457, 10], [999_999_999, 120]] as [$total, $count]) {
            $plan = $planner->plan(PurchaseMode::Installments, $total, $count);

            $this->assertCount($count, $plan);
            $this->assertSame($total, array_sum($plan));
        }
    }

    public function test_fixed_monthly_charges_repeat_the_known_amount(): void
    {
        $planner = new InstallmentPlanner;

        $this->assertSame([30_000, 30_000, 30_000, 30_000], $planner->plan(PurchaseMode::FixedMonthly, 30_000, 4));
        $this->assertSame(120_000, $planner->total(PurchaseMode::FixedMonthly, 30_000, 4));
    }

    public function test_at_least_one_installment_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new InstallmentPlanner)->plan(PurchaseMode::Installments, 10_000, 0);
    }
}
