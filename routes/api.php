<?php

declare(strict_types=1);

use App\Infrastructure\Http\Requests\QueryDebtsRequest;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok']);

// I8.2: placeholder closure validating QueryDebtsRequest behind the body-size
// middleware. I8.3 swaps this for `DebtsController` so the use case wires in.
Route::post('/debts/query', function (QueryDebtsRequest $request) {
    return ['placa' => $request->validated('placa')];
})->middleware('max_body_size');
