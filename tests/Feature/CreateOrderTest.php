<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CreateOrderTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create();
    }

    /** @param  array<int, array<string, mixed>>|null  $items */
    protected function payload(?array $items = null, ?array $overrides = null): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'items' => $items ?? [
                ['product_id' => 10, 'quantity' => 3, 'unit_price' => 250],
                ['product_id' => 15, 'quantity' => 2, 'unit_price' => 100],
            ],
        ], $overrides ?? []);
    }

    public function test_an_order_is_created_with_a_backend_calculated_breakdown(): void
    {
        // 6 × 2,000 = 12,000 → 5% → 600 discount → 11,400
        $response = $this->postJson('/api/orders', $this->payload([
            ['product_id' => 10, 'quantity' => 4, 'unit_price' => 2_000],
            ['product_id' => 15, 'quantity' => 2, 'unit_price' => 2_000],
        ]));

        $response->assertCreated()
            ->assertJsonPath('data.customer_id', $this->customer->id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subtotal', 12_000)
            ->assertJsonPath('data.discount_percentage', 5)
            ->assertJsonPath('data.discount', 600)
            ->assertJsonPath('data.total', 11_400)
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.line_total', 8_000);

        $this->assertDatabaseHas('orders', [
            'customer_id' => $this->customer->id,
            'status' => 'pending',
            'subtotal' => 12_000,
            'discount_percentage' => 5,
            'discount_amount' => 600,
            'total' => 11_400,
        ]);
        $this->assertSame(2, OrderItem::count());
    }

    public function test_a_client_supplied_total_or_discount_is_ignored(): void
    {
        $response = $this->postJson('/api/orders', $this->payload(
            [['product_id' => 10, 'quantity' => 1, 'unit_price' => 4_000]],
            [
                'subtotal' => 1,
                'discount' => 3_999,
                'discount_percentage' => 99,
                'total' => 1,
            ],
        ));

        $response->assertCreated()
            ->assertJsonPath('data.subtotal', 4_000)
            ->assertJsonPath('data.discount_percentage', 0)
            ->assertJsonPath('data.discount', 0)
            ->assertJsonPath('data.total', 4_000);
    }

    public function test_new_orders_always_start_as_pending(): void
    {
        $this->postJson('/api/orders', $this->payload(null, ['status' => 'completed']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame(OrderStatus::Pending, Order::firstOrFail()->status);
    }

    public function test_an_order_requires_at_least_one_item(): void
    {
        $this->postJson('/api/orders', $this->payload([]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('items')
            ->assertJsonPath('errors.items.0', 'An order must contain at least one item.');

        $this->postJson('/api/orders', ['customer_id' => $this->customer->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('items');

        $this->assertSame(0, Order::count());
    }

    public function test_item_quantity_must_be_greater_than_zero(): void
    {
        foreach ([0, -1] as $quantity) {
            $this->postJson('/api/orders', $this->payload([
                ['product_id' => 10, 'quantity' => $quantity, 'unit_price' => 250],
            ]))
                ->assertStatus(422)
                ->assertJsonValidationErrors([
                    'items.0.quantity' => 'Item quantity must be greater than zero.',
                ]);
        }

        $this->assertSame(0, Order::count());
    }

    public function test_unit_price_cannot_be_negative(): void
    {
        $this->postJson('/api/orders', $this->payload([
            ['product_id' => 10, 'quantity' => 1, 'unit_price' => -5],
        ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'items.0.unit_price' => 'Item unit price cannot be negative.',
            ]);

        $this->assertSame(0, Order::count());
    }

    public function test_a_zero_unit_price_is_accepted(): void
    {
        $this->postJson('/api/orders', $this->payload([
            ['product_id' => 10, 'quantity' => 1, 'unit_price' => 0],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.total', 0);
    }

    public function test_the_order_must_belong_to_an_existing_customer(): void
    {
        $this->postJson('/api/orders', $this->payload(null, ['customer_id' => 999_999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('customer_id');
    }

    public function test_malformed_payloads_are_rejected(): void
    {
        $this->postJson('/api/orders', ['customer_id' => 'abc', 'items' => 'not-an-array'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id', 'items']);

        $this->postJson('/api/orders', $this->payload([['quantity' => 1]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.product_id', 'items.0.unit_price']);
    }

    public function test_an_order_is_not_partially_created_when_something_fails(): void
    {
        // Force the item insert to fail after the order row has been written.
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into "order_items"')) {
                throw new \RuntimeException('simulated failure');
            }
        });

        try {
            $this->postJson('/api/orders', $this->payload());
        } catch (\Throwable) {
            // The exception itself is not what we are asserting on.
        }

        $this->assertSame(0, Order::count(), 'The order row should have been rolled back.');
        $this->assertSame(0, OrderItem::count());
    }
}
