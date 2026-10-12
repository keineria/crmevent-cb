<?php

use App\Http\Controllers\Reports\ReportController;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', Roles::middleware(Roles::REPORT_VIEWERS)])->prefix('reports')->name('reports.')->group(function () {
    Route::get('/', [ReportController::class, 'index'])->name('index');
    Route::get('categoria/{category}', [ReportController::class, 'category'])->name('category');
    Route::get('{section}', [ReportController::class, 'section'])->name('section');
    // The card goes in the query string: cards can be cities or countries with any character.
    Route::get('{section}/personas', [ReportController::class, 'people'])->name('people');
    Route::get('{section}/csv', [ReportController::class, 'export'])->name('export');
});
