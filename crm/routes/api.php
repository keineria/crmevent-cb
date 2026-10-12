<?php

use App\Http\Controllers\Public\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('subscribe', [SubscriptionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('api.subscribe');
});
