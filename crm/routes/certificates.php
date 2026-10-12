<?php

use App\Http\Controllers\Certificates\CertificateCategoryController;
use App\Http\Controllers\Certificates\CertificateController;
use App\Http\Controllers\Certificates\CertificateMailController;
use App\Http\Controllers\Certificates\CertificateSignerController;
use App\Http\Controllers\Certificates\CertificateVerificationController;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', Roles::middleware(Roles::CERTIFICATE_MANAGERS)])->prefix('certificados')->name('certificates.')->group(function () {
    Route::get('/', [CertificateController::class, 'index'])->name('index');
    Route::post('/', [CertificateController::class, 'store'])->name('store');

    Route::post('categorias', [CertificateCategoryController::class, 'store'])->name('categories.store');
    Route::whereNumber('category')->prefix('categorias/{category}')->name('categories.')->group(function () {
        Route::get('/', [CertificateCategoryController::class, 'edit'])->name('edit');
        Route::put('/', [CertificateCategoryController::class, 'update'])->name('update');
        Route::delete('/', [CertificateCategoryController::class, 'destroy'])->name('destroy');
        Route::post('vista-previa', [CertificateCategoryController::class, 'preview'])->middleware('throttle:60,1')->name('preview');
        Route::get('archivo/{kind}', [CertificateCategoryController::class, 'file'])->name('file');
        Route::post('archivo/{kind}', [CertificateCategoryController::class, 'upload'])->name('upload');
        Route::delete('archivo/{kind}', [CertificateCategoryController::class, 'removeFile'])->name('remove');
    });

    Route::get('firmantes', [CertificateSignerController::class, 'index'])->name('signers.index');
    Route::post('firmantes', [CertificateSignerController::class, 'store'])->name('signers.store');
    Route::whereNumber('signer')->prefix('firmantes/{signer}')->name('signers.')->group(function () {
        // POST with _method=PUT from the form, so the signature image can travel with it.
        Route::put('/', [CertificateSignerController::class, 'update'])->name('update');
        Route::delete('/', [CertificateSignerController::class, 'destroy'])->name('destroy');
        Route::get('firma', [CertificateSignerController::class, 'image'])->name('image');
    });

    // The certificate fonts, so the layout editor shows the same letters as the PDF.
    Route::get('fuentes/{font}', function (string $font) {
        abort_unless(in_array($font, ['PlayfairDisplay-Regular.ttf', 'PlayfairDisplay-Bold.ttf', 'PlayfairDisplay-Italic.ttf', 'Inter-Regular.ttf', 'Inter-SemiBold.ttf', 'GreatVibes-Regular.ttf'], true), 404);

        return response()->file(resource_path("fonts/certificates/{$font}"), ['Content-Type' => 'font/ttf', 'Cache-Control' => 'private, max-age=31536000, immutable']);
    })->name('fonts');

    Route::get('correo', [CertificateMailController::class, 'edit'])->name('mail.edit');
    Route::put('correo', [CertificateMailController::class, 'update'])->name('mail.update');
    Route::post('correo/vista-previa', [CertificateMailController::class, 'preview'])->middleware('throttle:120,1')->name('mail.preview');
    Route::post('correo/prueba', [CertificateMailController::class, 'test'])->middleware('throttle:10,1')->name('mail.test');

    Route::whereNumber('certificate')->group(function () {
        Route::get('{certificate}', [CertificateController::class, 'show'])->name('show');
        Route::put('{certificate}', [CertificateController::class, 'update'])->name('update');
        Route::delete('{certificate}', [CertificateController::class, 'destroy'])->name('destroy');
        Route::get('{certificate}/vista-previa', [CertificateController::class, 'preview'])->middleware('throttle:60,1')->name('preview');
        Route::post('{certificate}/prueba', [CertificateController::class, 'test'])->middleware('throttle:10,1')->name('test');
        Route::post('{certificate}/enviar', [CertificateController::class, 'send'])->middleware('throttle:5,1')->name('send');
    });

    Route::whereNumber('recipient')->group(function () {
        Route::get('persona/{recipient}/pdf', [CertificateController::class, 'download'])->name('recipient.pdf');
        Route::post('persona/{recipient}/reenviar', [CertificateController::class, 'resend'])->middleware('throttle:30,1')->name('recipient.resend');
    });
});

// Public: the QR on each certificate opens this page, so anyone can check it is genuine.
Route::get('certificados/verificar/{code}', [CertificateVerificationController::class, 'show'])
    ->middleware('throttle:30,1')->name('certificates.verify');
Route::get('certificados/verificar/{code}/pdf', [CertificateVerificationController::class, 'pdf'])
    ->middleware('throttle:10,1')->name('certificates.verify.pdf');
