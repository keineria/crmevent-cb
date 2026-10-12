<?php

use App\Http\Controllers\CheckIn\CheckInController;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', Roles::middleware(Roles::CHECKIN_STAFF)])->group(function () {
    Route::get('checkin', [CheckInController::class, 'index'])->name('checkin.index');
    Route::post('checkin', [CheckInController::class, 'store'])->middleware('throttle:120,1')->name('checkin.store');
    Route::delete('checkin/{checkIn}', [CheckInController::class, 'destroy'])->name('checkin.destroy');
    Route::get('checkin/qr/{participant}', [CheckInController::class, 'qr'])->name('checkin.qr');
});

Route::middleware(['auth', Roles::middleware(Roles::CHECKIN_MANAGERS)])->group(function () {
    Route::post('checkin/qr/{participant}/send', [CheckInController::class, 'sendQr'])->middleware('throttle:30,1')->name('checkin.qr.send');
    Route::post('checkin/qr-pending', [CheckInController::class, 'sendPendingQrs'])->middleware('throttle:3,1')->name('checkin.qr.pending');
});
