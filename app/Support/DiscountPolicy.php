<?php

namespace App\Support;

/**
 * Resolves the automatic discount for an order subtotal.
 *
 * The client never supplies a discount: it is derived here and nowhere else.
 */
class DiscountPolicy
{
    /** @var array<int, array{up_to: int|null, percentage: int}> */
    protected array $tiers;

    public function __construct(?array $tiers = null)
    {
        $this->tiers = $tiers ?? config('orders.discount_tiers');
    }

    /** The whole-number discount percentage for a given subtotal. */
    public function percentageFor(int $subtotal): int
    {
        foreach ($this->tiers as $tier) {
            if ($tier['up_to'] === null || $subtotal <= $tier['up_to']) {
                return (int) $tier['percentage'];
            }
        }

        return 0;
    }

    /**
     * The full pricing breakdown for a subtotal.
     *
     * The discount amount is rounded down so we never discount more than the
     * tier allows; subtotal - discount = total always holds.
     *
     * @return array{subtotal: int, discount_percentage: int, discount_amount: int, total: int}
     */
    public function apply(int $subtotal): array
    {
        $percentage = $this->percentageFor($subtotal);
        $discount = intdiv($subtotal * $percentage, 100);

        return [
            'subtotal' => $subtotal,
            'discount_percentage' => $percentage,
            'discount_amount' => $discount,
            'total' => $subtotal - $discount,
        ];
    }
}
