<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Models\Order;
use App\Support\DiscountPolicy;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class CreateOrder
{
    public function __construct(protected DiscountPolicy $discounts) {}

    /**
     * Create an order and its items as a single atomic operation.
     *
     * Returns the order plus whether it was replayed from a previous identical
     * request, so the caller can answer 200 instead of 201.
     *
     * @param  array<int, array{product_id: int, quantity: int, unit_price: int}>  $items
     * @return array{0: Order, 1: bool}
     */
    public function handle(int $customerId, array $items, ?string $idempotencyKey = null): array
    {
        $fingerprint = $this->fingerprint($customerId, $items);

        if ($existing = $this->findReplay($customerId, $fingerprint, $idempotencyKey)) {
            return [$existing->load('items'), true];
        }

        try {
            $order = DB::transaction(function () use ($customerId, $items, $idempotencyKey, $fingerprint) {
                $lines = $this->priceItems($items);
                $pricing = $this->discounts->apply(array_sum(array_column($lines, 'line_total')));

                $order = Order::create([
                    'customer_id' => $customerId,
                    'status' => OrderStatus::Pending,
                    'idempotency_key' => $idempotencyKey,
                    'fingerprint' => $fingerprint,
                    ...$pricing,
                ]);

                $order->items()->createMany($lines);

                return $order;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two identical requests raced: the loser returns the winner's order.
            $winner = Order::where('idempotency_key', $idempotencyKey)->first();

            if (! $winner) {
                throw $e;
            }

            return [$winner->load('items'), true];
        }

        return [$order->load('items'), false];
    }

    /**
     * Expand the requested items into priced lines.
     *
     * @param  array<int, array{product_id: int, quantity: int, unit_price: int}>  $items
     * @return array<int, array{product_id: int, quantity: int, unit_price: int, line_total: int}>
     */
    protected function priceItems(array $items): array
    {
        return array_map(fn (array $item) => [
            'product_id' => (int) $item['product_id'],
            'quantity' => (int) $item['quantity'],
            'unit_price' => (int) $item['unit_price'],
            'line_total' => (int) $item['quantity'] * (int) $item['unit_price'],
        ], $items);
    }

    /**
     * Look for an order that already satisfies this request.
     *
     * An explicit Idempotency-Key is authoritative; reusing one with a different
     * payload is a client error. Without a key we fall back to matching an
     * identical payload from the same customer inside a short window, which
     * covers clients that retry after losing the response.
     */
    protected function findReplay(int $customerId, string $fingerprint, ?string $idempotencyKey): ?Order
    {
        if ($idempotencyKey !== null) {
            $existing = Order::where('idempotency_key', $idempotencyKey)->first();

            if ($existing && $existing->fingerprint !== $fingerprint) {
                throw new IdempotencyConflictException($idempotencyKey);
            }

            return $existing;
        }

        $window = (int) config('orders.idempotency_window');

        if ($window <= 0) {
            return null;
        }

        return Order::query()
            ->where('customer_id', $customerId)
            ->where('fingerprint', $fingerprint)
            ->whereNull('idempotency_key')
            ->where('created_at', '>=', now()->subSeconds($window))
            ->latest('id')
            ->first();
    }

    /** A stable hash of the order payload, insensitive to item ordering and key order. */
    protected function fingerprint(int $customerId, array $items): string
    {
        $normalised = array_map(fn (array $item) => [
            'product_id' => (int) $item['product_id'],
            'quantity' => (int) $item['quantity'],
            'unit_price' => (int) $item['unit_price'],
        ], $items);

        sort($normalised);

        return hash('sha256', json_encode([
            'customer_id' => $customerId,
            'items' => $normalised,
        ]));
    }
}
