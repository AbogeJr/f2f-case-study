<?php

namespace Database\Seeders;

use App\Actions\CreateOrder;
use App\Models\Customer;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Fixed ids so the example request in the README works out of the box.
        $customers = collect([123, 124, 125])->map(fn (int $id) => Customer::firstOrCreate(
            ['id' => $id],
            ['name' => fake()->name(), 'email' => fake()->unique()->safeEmail()],
        ));

        $createOrder = app(CreateOrder::class);

        // One order per discount tier, so the seeded data exercises every rule.
        $baskets = [
            [['product_id' => 10, 'quantity' => 3, 'unit_price' => 1_500]],            // 4,500  → 0%
            [['product_id' => 11, 'quantity' => 4, 'unit_price' => 2_000]],            // 8,000  → 2%
            [['product_id' => 10, 'quantity' => 3, 'unit_price' => 250],
                ['product_id' => 15, 'quantity' => 2, 'unit_price' => 100]],           // 950    → 0%
            [['product_id' => 12, 'quantity' => 6, 'unit_price' => 2_000]],            // 12,000 → 5%
            [['product_id' => 13, 'quantity' => 5, 'unit_price' => 5_000]],            // 25,000 → 10%
        ];

        foreach ($baskets as $i => $items) {
            $createOrder->handle(
                customerId: $customers[$i % $customers->count()]->id,
                items: $items,
                idempotencyKey: 'seed-'.$i,
            );
        }
    }
}
