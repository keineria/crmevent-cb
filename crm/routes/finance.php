<?php

use App\Http\Controllers\Finance\ExpenseController;
use App\Http\Controllers\Finance\FinanceController;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', Roles::middleware(Roles::FINANCE_VIEWERS)])->prefix('finance')->name('finance.')->group(function () {
    Route::get('/', FinanceController::class)->name('index');
    Route::get('expenses', [ExpenseController::class, 'index'])->name('expenses.index');
});

Route::middleware(['auth', Roles::middleware(Roles::FINANCE_EDITORS)])->prefix('finance')->name('finance.')->group(function () {
    Route::post('expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::put('expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
    Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
});
