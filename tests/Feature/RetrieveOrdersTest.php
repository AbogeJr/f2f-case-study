<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetrieveOrdersTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_order_is_returned_with_its_full_pricing_breakdown(): void
    {
        $order = Order::factory()->subtotal(12_000)->create();
        OrderItem::factory()->create([
            'order_id' => $order->id,
            'product_id' => 10,
            'quantity' => 6,
            'unit_price' => 2_000,
            'line_total' => 12_000,
        ]);

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $order->id)
            ->assertJsonPath('data.customer_id', $order->customer_id)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.subtotal', 12_000)
            ->assertJsonPath('data.discount', 600)
            ->assertJsonPath('data.discount_percentage', 5)
            ->assertJsonPath('data.total', 11_400)
            ->assertJsonPath('data.items.0.product_id', 10)
            ->assertJsonPath('data.items.0.quantity', 6)
            ->assertJsonPath('data.items.0.unit_price', 2_000)
            ->assertJsonPath('data.items.0.line_total', 12_000)
            ->assertJsonStructure([
                'data' => [
                    'id', 'customer_id', 'status', 'subtotal', 'discount',
                    'discount_percentage', 'total', 'allowed_transitions',
                    'items' => [['id', 'product_id', 'quantity', 'unit_price', 'line_total']],
                ],
            ]);
    }

    public function test_a_missing_order_returns_404(): void
    {
        $this->getJson('/api/orders/999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Order not found.');
    }

    public function test_an_unknown_endpoint_returns_a_clean_404(): void
    {
        $this->getJson('/api/nope')
            ->assertNotFound()
            ->assertJsonPath('message', 'The requested endpoint does not exist.');
    }

    public function test_an_unsupported_method_returns_405(): void
    {
        $this->putJson('/api/orders')
            ->assertStatus(405)
            ->assertJsonStructure(['message']);
    }

    public function test_orders_can_be_listed(): void
    {
        Order::factory()->count(3)->create();

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'customer_id', 'status', 'subtotal', 'discount', 'total', 'items']],
                'links',
                'meta' => ['current_page', 'per_page', 'total'],
            ]);
    }

    public function test_the_list_is_paginated(): void
    {
        Order::factory()->count(5)->create();

        $this->getJson('/api/orders?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.per_page', 2);
    }

    public function test_the_list_can_be_filtered_by_status_and_customer(): void
    {
        $customer = Customer::factory()->create();

        $wanted = Order::factory()->for($customer)->status(OrderStatus::Processing)->create();
        Order::factory()->for($customer)->status(OrderStatus::Pending)->create();
        Order::factory()->status(OrderStatus::Processing)->create();

        $this->getJson('/api/orders?status=processing')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson("/api/orders?status=processing&customer_id={$customer->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $wanted->id);
    }

    public function test_an_empty_list_is_returned_when_there_are_no_orders(): void
    {
        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }
}
