<?php

namespace App\Http\Controllers\Api;

use App\Actions\CreateOrder;
use App\Actions\UpdateOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrderRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $orders = Order::query()
            ->status($request->query('status'))
            ->forCustomer($request->query('customer_id'))
            ->with('items')
            ->latest('id')
            ->paginate(perPage: min((int) $request->query('per_page', 15), 100))
            ->withQueryString();

        return OrderResource::collection($orders);
    }

    public function store(StoreOrderRequest $request, CreateOrder $createOrder): JsonResponse
    {
        [$order, $replayed] = $createOrder->handle(
            customerId: (int) $request->validated('customer_id'),
            items: $request->validated('items'),
            idempotencyKey: $request->idempotencyKey(),
        );

        // A replayed retry returns the original order rather than creating a second one.
        return OrderResource::make($order)
            ->response()
            ->setStatusCode($replayed ? 200 : 201);
    }

    public function show(Order $order): OrderResource
    {
        return OrderResource::make($order->load('items'));
    }

    public function updateStatus(
        UpdateOrderStatusRequest $request,
        Order $order,
        UpdateOrderStatus $updateStatus,
    ): OrderResource {
        $updateStatus->handle($order, $request->status());

        return OrderResource::make($order->load('items'));
    }
}
