<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create();
    }

    protected function payload(?array $items = null): array
    {
        return [
            'customer_id' => $this->customer->id,
            'items' => $items ?? [
                ['product_id' => 10, 'quantity' => 3, 'unit_price' => 250],
                ['product_id' => 15, 'quantity' => 2, 'unit_price' => 100],
            ],
        ];
    }

    public function test_retrying_with_the_same_idempotency_key_returns_the_original_order(): void
    {
        $headers = ['Idempotency-Key' => 'abc-123'];

        $first = $this->postJson('/api/orders', $this->payload(), $headers)->assertCreated();
        $second = $this->postJson('/api/orders', $this->payload(), $headers)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::count(), 'The retry must not create a second order.');
        $this->assertSame(2, OrderItem::count());
    }

    public function test_reusing_a_key_with_a_different_payload_is_a_conflict(): void
    {
        $headers = ['Idempotency-Key' => 'abc-123'];

        $this->postJson('/api/orders', $this->payload(), $headers)->assertCreated();

        $this->postJson('/api/orders', $this->payload([
            ['product_id' => 99, 'quantity' => 1, 'unit_price' => 10_000],
        ]), $headers)
            ->assertStatus(409)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, 'abc-123'));

        $this->assertSame(1, Order::count());
    }

    public function test_different_keys_create_different_orders(): void
    {
        $this->postJson('/api/orders', $this->payload(), ['Idempotency-Key' => 'key-1'])->assertCreated();
        $this->postJson('/api/orders', $this->payload(), ['Idempotency-Key' => 'key-2'])->assertCreated();

        $this->assertSame(2, Order::count());
    }

    public function test_an_identical_payload_without_a_key_is_treated_as_a_retry_inside_the_window(): void
    {
        $first = $this->postJson('/api/orders', $this->payload())->assertCreated();
        $second = $this->postJson('/api/orders', $this->payload())->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::count());
    }

    public function test_the_fallback_window_expires(): void
    {
        $this->postJson('/api/orders', $this->payload())->assertCreated();

        $this->travel(config('orders.idempotency_window') + 1)->seconds();

        $this->postJson('/api/orders', $this->payload())->assertCreated();

        $this->assertSame(2, Order::count(), 'Beyond the window a repeat is a genuine new order.');
    }

    public function test_the_fallback_does_not_match_a_different_customer_or_basket(): void
    {
        $other = Customer::factory()->create();

        $this->postJson('/api/orders', $this->payload())->assertCreated();

        $this->postJson('/api/orders', [
            'customer_id' => $other->id,
            'items' => $this->payload()['items'],
        ])->assertCreated();

        $this->postJson('/api/orders', $this->payload([
            ['product_id' => 10, 'quantity' => 1, 'unit_price' => 250],
        ]))->assertCreated();

        $this->assertSame(3, Order::count());
    }

    public function test_item_ordering_does_not_affect_the_fingerprint(): void
    {
        $items = $this->payload()['items'];

        $first = $this->postJson('/api/orders', $this->payload($items))->assertCreated();
        $second = $this->postJson('/api/orders', $this->payload(array_reverse($items)))->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Order::count());
    }
}
