<?php

namespace App\Exceptions;

use App\Enums\OrderStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvalidStatusTransitionException extends \DomainException
{
    public function __construct(
        public readonly OrderStatus $from,
        public readonly OrderStatus $to,
    ) {
        parent::__construct($from->isFinal()
            ? "Order is {$from->value} and can no longer be modified."
            : "An order cannot move from {$from->value} to {$to->value}.");
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'status' => [$this->getMessage()],
            ],
            'allowed_transitions' => array_column($this->from->allowedTransitions(), 'value'),
        ], 422);
    }
}
