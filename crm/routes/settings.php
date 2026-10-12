<?php

use App\Http\Controllers\Settings\AppearanceController;
use App\Http\Controllers\Settings\AuditLogController;
use App\Http\Controllers\Settings\BrandingController;
use App\Http\Controllers\Settings\MailSettingsController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\PluginConnectionController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\UserController;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', [AppearanceController::class, 'edit'])->name('appearance');
    Route::patch('settings/appearance', [AppearanceController::class, 'update'])->name('appearance.update');
});

// Logos are public: the login page and emails show them to people who aren't signed in.
Route::get('marca/{kind}', [BrandingController::class, 'show'])->name('branding.logo');

Route::middleware(['auth', 'role:'.Roles::ADMIN])->group(function () {
    Route::get('settings/branding', [BrandingController::class, 'edit'])->name('branding.edit');
    Route::post('settings/branding/{kind}', [BrandingController::class, 'update'])->name('branding.update');
    Route::delete('settings/branding/{kind}', [BrandingController::class, 'destroy'])->name('branding.destroy');

    Route::get('settings/mail', [MailSettingsController::class, 'edit'])->name('mail-settings.edit');
    Route::put('settings/mail/{profile}', [MailSettingsController::class, 'update'])->name('mail-settings.update');
    Route::post('settings/mail/{profile}/test', [MailSettingsController::class, 'test'])
        ->middleware('throttle:10,1')
        ->name('mail-settings.test');
    Route::post('settings/mail/{profile}/dns', [MailSettingsController::class, 'dns'])
        ->middleware('throttle:20,1')
        ->name('mail-settings.dns');

    Route::get('settings/plugin', [PluginConnectionController::class, 'edit'])->name('plugin-connection.edit');
    Route::put('settings/plugin', [PluginConnectionController::class, 'update'])->name('plugin-connection.update');
    Route::post('settings/plugin/test', [PluginConnectionController::class, 'test'])->middleware('throttle:10,1')->name('plugin-connection.test');
    Route::get('settings/users', [UserController::class, 'index'])->name('users.index');
    Route::post('settings/users', [UserController::class, 'store'])->name('users.store');
    Route::put('settings/users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('settings/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
    Route::post('settings/users/{user}/activate', [UserController::class, 'activate'])->name('users.activate');
    Route::post('settings/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::get('settings/audit', [AuditLogController::class, 'index'])->name('audit.index');
    Route::get('settings/audit/export', [AuditLogController::class, 'export'])->middleware('throttle:10,1')->name('audit.export');

    Route::post('settings/plugin/sync', [PluginConnectionController::class, 'sync'])->middleware('throttle:10,1')->name('plugin-connection.sync');
});
