<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Note what is absent: subtotal, discount and total are never accepted from
     * the client. Anything sent under those keys is ignored.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer', 'exists:customers,id'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.unit_price' => ['required', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'items.required' => 'An order must contain at least one item.',
            'items.array' => 'An order must contain at least one item.',
            'items.min' => 'An order must contain at least one item.',
            'items.*.quantity.min' => 'Item quantity must be greater than zero.',
            'items.*.unit_price.min' => 'Item unit price cannot be negative.',
            'items.*.unit_price.integer' => 'Item unit price must be an integer in the smallest currency unit.',
            'customer_id.exists' => 'The selected customer does not exist.',
        ];
    }

    public function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return filled($key) ? (string) $key : null;
    }
}
