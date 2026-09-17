<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Discount tiers
    |--------------------------------------------------------------------------
    |
    | Applied to the order subtotal. The first tier whose "up_to" bound is not
    | exceeded wins; "up_to" => null is the open-ended top tier. Bounds are
    | inclusive, so a subtotal of exactly 5,000 falls in the 0% tier.
    |
    */

    'discount_tiers' => [
        ['up_to' => 5_000, 'percentage' => 0],
        ['up_to' => 10_000, 'percentage' => 2],
        ['up_to' => 20_000, 'percentage' => 5],
        ['up_to' => null, 'percentage' => 10],
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency fallback window
    |--------------------------------------------------------------------------
    |
    | When a client creates an order without an Idempotency-Key header, an
    | identical payload from the same customer within this many seconds is
    | treated as a retry rather than a new order. Set to 0 to disable.
    |
    */

    'idempotency_window' => (int) env('ORDERS_IDEMPOTENCY_WINDOW', 60),

];
