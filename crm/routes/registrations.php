<?php

use App\Http\Controllers\Registrations\RegistrationController;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', Roles::middleware(Roles::REGISTRATION_VIEWERS)])->group(function () {
    Route::get('registrations', [RegistrationController::class, 'index'])->name('registrations.index');
    Route::get('activities', [RegistrationController::class, 'activities'])->name('activities.index');
});
