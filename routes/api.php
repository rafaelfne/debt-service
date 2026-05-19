<?php

declare(strict_types=1);

use App\Infrastructure\Http\Controllers\DebtsController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok']);

Route::post('/debts/query', DebtsController::class)
    ->middleware('max_body_size');
