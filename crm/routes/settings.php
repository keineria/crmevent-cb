<?php

use App\Http\Controllers\Settings\MailSettingsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');
});

Route::middleware(['auth', 'role:'.Roles::ADMIN])->group(function () {
    Route::get('settings/mail', [MailSettingsController::class, 'edit'])->name('mail-settings.edit');
    Route::put('settings/mail/{profile}', [MailSettingsController::class, 'update'])->name('mail-settings.update');
    Route::post('settings/mail/{profile}/test', [MailSettingsController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('mail-settings.test');
    Route::post('settings/mail/{profile}/dns', [MailSettingsController::class, 'dns'])
        ->middleware('throttle:20,1')
        ->name('mail-settings.dns');
});
