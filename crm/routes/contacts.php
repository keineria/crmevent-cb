<?php

use App\Http\Controllers\Contacts\ContactController;
use App\Http\Controllers\Contacts\ContactImportController;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', Roles::middleware(Roles::CONTACT_VIEWERS)])->group(function () {
    Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
});

Route::middleware(['auth', Roles::middleware(Roles::CONTACT_IMPORTERS)])->prefix('contacts/import')->name('contacts.import.')->group(function () {
    Route::get('/', [ContactImportController::class, 'create'])->name('create');
    Route::post('/', [ContactImportController::class, 'store'])->name('store');
    Route::get('{import}', [ContactImportController::class, 'show'])->name('show');
    Route::post('{import}/run', [ContactImportController::class, 'run'])->name('run');
});
