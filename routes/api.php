<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => ['status' => 'ok']);
