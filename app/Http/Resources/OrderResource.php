<?php

namespace App\Http\Resources;

use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Order */
class OrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customer_id,
            'status' => $this->status->value,
            'subtotal' => $this->subtotal,
            'discount' => $this->discount_amount,
            'discount_percentage' => $this->discount_percentage,
            'total' => $this->total,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'allowed_transitions' => array_column($this->status->allowedTransitions(), 'value'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
