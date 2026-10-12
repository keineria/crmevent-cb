<?php

use App\Http\Controllers\Public\SurveyResponseController;
use App\Http\Controllers\Surveys\SurveyController;
use App\Http\Controllers\Surveys\SurveyMailController;
use App\Http\Controllers\Surveys\SurveyResultController;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', Roles::middleware(Roles::SURVEY_MANAGERS)])->prefix('encuestas')->name('surveys.')->group(function () {
    Route::get('/', [SurveyController::class, 'index'])->name('index');

    Route::get('correo', [SurveyMailController::class, 'edit'])->name('mail.edit');
    Route::put('correo', [SurveyMailController::class, 'update'])->name('mail.update');
    Route::post('correo/vista-previa', [SurveyMailController::class, 'preview'])->middleware('throttle:120,1')->name('mail.preview');
    Route::post('correo/prueba', [SurveyMailController::class, 'test'])->middleware('throttle:10,1')->name('mail.test');

    Route::whereIn('type', ['conference', 'workshop', 'birding', 'other'])->prefix('preguntas/{type}')->name('forms.')->group(function () {
        Route::get('/', [SurveyController::class, 'edit'])->name('edit');
        Route::put('/', [SurveyController::class, 'update'])->name('update');
        Route::put('activa', [SurveyController::class, 'toggle'])->name('toggle');
    });

    Route::post('actividad/{activity}/enviar', [SurveyController::class, 'open'])->whereNumber('activity')->middleware('throttle:10,1')->name('open');
    Route::post('{survey}/enviar', [SurveyController::class, 'send'])->whereNumber('survey')->middleware('throttle:10,1')->name('send');
    Route::post('persona/{invitation}/reenviar', [SurveyController::class, 'resend'])->whereNumber('invitation')->middleware('throttle:30,1')->name('resend');
});

// Results live in Reportes; registered before reports.php so "encuestas" is not taken for a report section.
Route::middleware(['auth', Roles::middleware(Roles::SURVEY_VIEWERS)])->prefix('reports/encuestas')->name('surveys.results.')->group(function () {
    Route::get('/', [SurveyResultController::class, 'index'])->name('index');
    Route::get('{survey}', [SurveyResultController::class, 'show'])->whereNumber('survey')->name('show');
    Route::get('{survey}/csv', [SurveyResultController::class, 'export'])->whereNumber('survey')->name('export');
});

// Public: the link in the survey email. The token is the only key, so it can't be guessed.
Route::get('encuesta/{token}', [SurveyResponseController::class, 'show'])->middleware('throttle:30,1')->name('surveys.public.show');
Route::post('encuesta/{token}', [SurveyResponseController::class, 'store'])->middleware('throttle:10,1')->name('surveys.public.store');
