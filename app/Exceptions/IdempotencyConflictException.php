<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdempotencyConflictException extends \RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct(
            "The Idempotency-Key '{$key}' was already used for a request with a different payload."
        );
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
        ], 409);
    }
}
