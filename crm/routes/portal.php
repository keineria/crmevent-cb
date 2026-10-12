<?php

use App\Http\Controllers\Portal\PortalController;
use Illuminate\Support\Facades\Route;

// Participant portal: read-only lookup of one's own registration by document number.
Route::prefix('inscritos')->name('portal.')->group(function () {
    Route::get('/', [PortalController::class, 'show'])->name('show');
    Route::post('consultar', [PortalController::class, 'lookup'])->middleware('throttle:10,1')->name('lookup');
    Route::post('salir', [PortalController::class, 'clear'])->name('clear');
});
