<?php

use App\Http\Controllers\CheckIn\CheckInController;
use App\Http\Controllers\CheckIn\ParticipantEditController;
use App\Http\Controllers\CheckIn\QrMailController;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', Roles::middleware(Roles::CHECKIN_STAFF)])->group(function () {
    Route::get('checkin', [CheckInController::class, 'index'])->name('checkin.index');
    Route::post('checkin', [CheckInController::class, 'store'])->middleware('throttle:120,1')->name('checkin.store');
    Route::delete('checkin/{checkIn}', [CheckInController::class, 'destroy'])->name('checkin.destroy');
    Route::get('checkin/qr/{participant}', [CheckInController::class, 'qr'])->name('checkin.qr');
});

Route::middleware(['auth', Roles::middleware(Roles::PARTICIPANT_EDITORS), 'throttle:30,1'])->group(function () {
    Route::put('checkin/persona/{participant}/datos', [ParticipantEditController::class, 'details'])->name('checkin.person.details');
});

Route::middleware(['auth', Roles::middleware(Roles::ACTIVITY_EDITORS), 'throttle:30,1'])->group(function () {
    Route::get('checkin/actividades-disponibles', [ParticipantEditController::class, 'available'])->name('checkin.available');
    Route::post('checkin/actividad/{activity}/cambiar', [ParticipantEditController::class, 'replace'])->name('checkin.activity.replace');
    Route::post('checkin/actividad/{activity}/retirar', [ParticipantEditController::class, 'cancel'])->name('checkin.activity.cancel');
    Route::post('checkin/persona/{participant}/agregar/revisar', [ParticipantEditController::class, 'preview'])->name('checkin.person.preview');
    Route::post('checkin/persona/{participant}/agregar', [ParticipantEditController::class, 'add'])->name('checkin.person.add');
});

Route::middleware(['auth', Roles::middleware(Roles::CHECKIN_MANAGERS)])->group(function () {
    Route::post('checkin/qr/{participant}/send', [CheckInController::class, 'sendQr'])->middleware('throttle:30,1')->name('checkin.qr.send');
    Route::post('checkin/qr-pending', [CheckInController::class, 'sendPendingQrs'])->middleware('throttle:3,1')->name('checkin.qr.pending');
});

Route::middleware(['auth', Roles::middleware(Roles::QR_MAIL_EDITORS)])->group(function () {
    Route::get('checkin/correo-qr', [QrMailController::class, 'edit'])->name('checkin.qr_mail.edit');
    Route::put('checkin/correo-qr', [QrMailController::class, 'update'])->name('checkin.qr_mail.update');
    Route::post('checkin/correo-qr/vista-previa', [QrMailController::class, 'preview'])->middleware('throttle:120,1')->name('checkin.qr_mail.preview');
    Route::post('checkin/correo-qr/prueba', [QrMailController::class, 'test'])->middleware('throttle:10,1')->name('checkin.qr_mail.test');
});
