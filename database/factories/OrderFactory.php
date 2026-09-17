<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Support\DiscountPolicy;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Order> */
class OrderFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $subtotal = fake()->numberBetween(1_000, 30_000);

        return [
            'customer_id' => Customer::factory(),
            'status' => OrderStatus::Pending,
            ...app(DiscountPolicy::class)->apply($subtotal),
        ];
    }

    public function status(OrderStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    /** Build an order whose stored pricing matches the given subtotal. */
    public function subtotal(int $subtotal): static
    {
        return $this->state(fn () => app(DiscountPolicy::class)->apply($subtotal));
    }
}
