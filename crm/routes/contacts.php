<?php

use App\Http\Controllers\Contacts\ContactController;
use App\Http\Controllers\Contacts\ContactFormController;
use App\Http\Controllers\Contacts\ContactImportController;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', Roles::middleware(Roles::CONTACT_EDITORS)])->group(function () {
    Route::get('contacts/create', [ContactFormController::class, 'create'])->name('contacts.create');
    Route::post('contacts', [ContactFormController::class, 'store'])->name('contacts.store');
    Route::get('contacts/{contact}/edit', [ContactFormController::class, 'edit'])->whereNumber('contact')->name('contacts.edit');
    Route::put('contacts/{contact}', [ContactFormController::class, 'update'])->whereNumber('contact')->name('contacts.update');
});

Route::middleware(['auth', Roles::middleware(Roles::CONTACT_VIEWERS)])->group(function () {
    Route::get('contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::get('contacts/{contact}', [ContactController::class, 'show'])->whereNumber('contact')->name('contacts.show');
});

Route::middleware(['auth', Roles::middleware(Roles::CONTACT_IMPORTERS)])->prefix('contacts/import')->name('contacts.import.')->group(function () {
    Route::get('/', [ContactImportController::class, 'create'])->name('create');
    Route::post('/', [ContactImportController::class, 'store'])->name('store');
    Route::get('plantilla', [ContactImportController::class, 'template'])->name('template');
    Route::get('{import}', [ContactImportController::class, 'show'])->name('show');
    Route::post('{import}/run', [ContactImportController::class, 'run'])->name('run');
});
