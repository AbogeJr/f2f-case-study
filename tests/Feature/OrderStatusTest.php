<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class OrderStatusTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('allowedTransitions')]
    public function test_allowed_transitions_are_accepted(string $from, string $to): void
    {
        $order = Order::factory()->status(OrderStatus::from($from))->create();

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => $to])
            ->assertOk()
            ->assertJsonPath('data.status', $to);

        $this->assertSame($to, $order->fresh()->status->value);
    }

    /** @return array<string, array{string, string}> */
    public static function allowedTransitions(): array
    {
        return [
            'pending → processing' => ['pending', 'processing'],
            'pending → cancelled' => ['pending', 'cancelled'],
            'processing → completed' => ['processing', 'completed'],
            'processing → cancelled' => ['processing', 'cancelled'],
        ];
    }

    #[DataProvider('forbiddenTransitions')]
    public function test_forbidden_transitions_are_rejected(string $from, string $to): void
    {
        $order = Order::factory()->status(OrderStatus::from($from))->create();

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => $to])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame($from, $order->fresh()->status->value, 'The status must not have changed.');
    }

    /**
     * Every pair the specification does not allow, including no-op transitions
     * and anything leaving a final status.
     *
     * @return array<string, array{string, string}>
     */
    public static function forbiddenTransitions(): array
    {
        $allowed = array_map(fn ($pair) => implode('→', $pair), self::allowedTransitions());
        $cases = [];

        foreach (OrderStatus::values() as $from) {
            foreach (OrderStatus::values() as $to) {
                if (! in_array("{$from}→{$to}", $allowed, true)) {
                    $cases["{$from} → {$to}"] = [$from, $to];
                }
            }
        }

        return $cases;
    }

    public function test_a_completed_order_cannot_be_modified(): void
    {
        $order = Order::factory()->status(OrderStatus::Completed)->create();

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'processing'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Order is completed and can no longer be modified.')
            ->assertJsonPath('allowed_transitions', []);
    }

    public function test_a_cancelled_order_cannot_be_modified(): void
    {
        $order = Order::factory()->status(OrderStatus::Cancelled)->create();

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'processing'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Order is cancelled and can no longer be modified.');
    }

    public function test_an_unknown_status_is_rejected(): void
    {
        $order = Order::factory()->status(OrderStatus::Pending)->create();

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'shipped'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->patchJson("/api/orders/{$order->id}/status", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);
    }

    public function test_the_error_lists_the_transitions_that_are_allowed(): void
    {
        $order = Order::factory()->status(OrderStatus::Pending)->create();

        $this->patchJson("/api/orders/{$order->id}/status", ['status' => 'completed'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'An order cannot move from pending to completed.')
            ->assertJsonPath('allowed_transitions', ['processing', 'cancelled']);
    }

    public function test_updating_the_status_of_a_missing_order_returns_404(): void
    {
        $this->patchJson('/api/orders/999/status', ['status' => 'processing'])
            ->assertNotFound();
    }
}
