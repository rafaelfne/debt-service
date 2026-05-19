<?php

declare(strict_types=1);

use App\Infrastructure\Mocks\ProviderAMockController;
use App\Infrastructure\Mocks\ProviderBMockController;
use App\Infrastructure\Mocks\ProviderCMockController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Provider mocks — só ativos fora de produção (decisão do gate Feature 6).
if (! app()->isProduction()) {
    Route::get('/mock/provider-a/debts/{plate}', [ProviderAMockController::class, 'show'])
        ->where('plate', '[A-Za-z0-9]+');

    Route::get('/mock/provider-b/debts/{plate}', [ProviderBMockController::class, 'show'])
        ->where('plate', '[A-Za-z0-9]+');

    Route::get('/mock/provider-c/debts/{plate}', [ProviderCMockController::class, 'show'])
        ->where('plate', '[A-Za-z0-9]+');
}
