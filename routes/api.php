<?php

use Illuminate\Support\Facades\Route;

// API routes are mapped in web.php to support exact FastAPI parity without automatic /api prefix
Route::get('/ping', function () {
    return response()->json(['pong' => true]);
});
