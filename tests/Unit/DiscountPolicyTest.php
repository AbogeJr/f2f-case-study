<?php

namespace Tests\Unit;

use App\Support\DiscountPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DiscountPolicyTest extends TestCase
{
    protected DiscountPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new DiscountPolicy;
    }

    #[DataProvider('boundaries')]
    public function test_discount_percentage_at_tier_boundaries(int $subtotal, int $expected): void
    {
        $this->assertSame(
            $expected,
            $this->policy->percentageFor($subtotal),
            "Subtotal {$subtotal} should attract {$expected}% discount."
        );
    }

    /** @return array<string, array{int, int}> */
    public static function boundaries(): array
    {
        return [
            'zero' => [0, 0],
            'well below first tier' => [4_500, 0],
            'exactly 5,000 (inclusive upper bound)' => [5_000, 0],
            'just over 5,000' => [5_001, 2],
            'exactly 10,000 (inclusive upper bound)' => [10_000, 2],
            'just over 10,000' => [10_001, 5],
            'exactly 20,000 (inclusive upper bound)' => [20_000, 5],
            'just over 20,000' => [20_001, 10],
            'far above the top tier' => [1_000_000, 10],
        ];
    }

    #[DataProvider('workedExamples')]
    public function test_the_examples_from_the_specification(int $subtotal, int $discount, int $total): void
    {
        $pricing = $this->policy->apply($subtotal);

        $this->assertSame($discount, $pricing['discount_amount']);
        $this->assertSame($total, $pricing['total']);
    }

    /** @return array<string, array{int, int, int}> */
    public static function workedExamples(): array
    {
        return [
            'order A' => [4_500, 0, 4_500],
            'order B' => [8_000, 160, 7_840],
            'order C' => [12_000, 600, 11_400],
            'order D' => [25_000, 2_500, 22_500],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_subtotal_minus_discount_always_equals_total(int $subtotal): void
    {
        $pricing = $this->policy->apply($subtotal);

        $this->assertSame(
            $pricing['subtotal'] - $pricing['discount_amount'],
            $pricing['total']
        );
    }

    public function test_the_discount_amount_is_rounded_down(): void
    {
        // 2% of 5,001 is 100.02 — we never discount more than the tier allows.
        $this->assertSame(100, $this->policy->apply(5_001)['discount_amount']);
        $this->assertSame(4_901, $this->policy->apply(5_001)['total']);
    }

    public function test_tiers_are_configurable(): void
    {
        $policy = new DiscountPolicy([
            ['up_to' => 100, 'percentage' => 0],
            ['up_to' => null, 'percentage' => 50],
        ]);

        $this->assertSame(0, $policy->percentageFor(100));
        $this->assertSame(50, $policy->percentageFor(101));
        $this->assertSame(100, $policy->apply(200)['total']);
    }
}
