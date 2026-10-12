<?php

use App\Http\Controllers\Stands\StandWorkbookController;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', Roles::middleware(Roles::STAND_VIEWERS)])->prefix('stands')->name('stands.')->group(function () {
    Route::get('/', [StandWorkbookController::class, 'index'])->name('index');
    Route::whereNumber('workbook')->get('{workbook}', [StandWorkbookController::class, 'show'])->name('show');
    Route::whereNumber('workbook')->get('{workbook}/descargar', [StandWorkbookController::class, 'download'])->name('download');
});

Route::middleware(['auth', Roles::middleware(Roles::STAND_EDITORS)])->prefix('stands')->name('stands.')->group(function () {
    Route::post('/', [StandWorkbookController::class, 'store'])->name('store');
    Route::whereNumber('workbook')->prefix('{workbook}')->group(function () {
        Route::patch('celda', [StandWorkbookController::class, 'updateCell'])->middleware('throttle:240,1')->name('cell');
        Route::post('reemplazar', [StandWorkbookController::class, 'replace'])->name('replace');
        Route::delete('/', [StandWorkbookController::class, 'destroy'])->name('destroy');
    });
});
