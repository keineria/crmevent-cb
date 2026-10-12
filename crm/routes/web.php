<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Public\CampaignTrackingController;
use App\Http\Controllers\Public\PassController;
use App\Http\Controllers\Public\SubscriptionController;
use App\Http\Controllers\Public\UnsubscribeController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
});

// Public pages linked from emails.
Route::get('suscripcion/confirmar/{contact}', [SubscriptionController::class, 'confirm'])
    ->middleware(['signed', 'throttle:20,1'])
    ->name('subscription.confirm');
Route::get('baja/{token}', [UnsubscribeController::class, 'show'])->name('unsubscribe.show');
Route::post('baja/{token}', [UnsubscribeController::class, 'store'])->middleware('throttle:20,1')->name('unsubscribe.store');

Route::get('e/o/{token}.gif', [CampaignTrackingController::class, 'open'])->middleware('throttle:120,1')->name('campaigns.track.open');
Route::get('e/c/{token}', [CampaignTrackingController::class, 'click'])->middleware('throttle:120,1')->name('campaigns.track.click');

// Participant's calendar and wallet passes, linked from the QR email and the portal.
Route::middleware('throttle:30,1')->prefix('pase/{participant}/{token}')->name('passes.')->group(function () {
    Route::get('calendario.ics', [PassController::class, 'calendar'])->name('calendar');
    Route::get('apple', [PassController::class, 'apple'])->name('apple');
    Route::get('google', [PassController::class, 'google'])->name('google');
});

require __DIR__.'/contacts.php';
require __DIR__.'/campaigns.php';
require __DIR__.'/finance.php';
require __DIR__.'/registrations.php';
require __DIR__.'/checkin.php';
require __DIR__.'/certificates.php';
require __DIR__.'/portal.php';
require __DIR__.'/reports.php';
require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
