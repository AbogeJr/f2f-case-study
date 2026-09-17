<?php

use Illuminate\Support\Facades\Route;

// This application exposes a JSON API only; see routes/api.php.
Route::get('/', fn () => response()->json([
    'application' => config('app.name'),
    'api' => [
        'list_orders' => url('/api/orders'),
        'documentation' => 'See README.md',
    ],
]));
