<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Exceptions\InvalidStatusTransitionException;
use App\Models\Order;

class UpdateOrderStatus
{
    /**
     * Move an order to a new status, enforcing the allowed transitions.
     *
     * @throws InvalidStatusTransitionException
     */
    public function handle(Order $order, OrderStatus $status): Order
    {
        if (! $order->status->canTransitionTo($status)) {
            throw new InvalidStatusTransitionException($order->status, $status);
        }

        $order->status = $status;
        $order->save();

        return $order;
    }
}
