<?php

use App\Http\Controllers\Campaigns\CampaignController;
use App\Http\Controllers\Campaigns\MailTemplateController;
use App\Support\Roles;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', Roles::middleware(Roles::CAMPAIGN_EDITORS)])->prefix('campaigns')->name('campaigns.')->group(function () {
    Route::post('/', [CampaignController::class, 'store'])->name('store');
    Route::post('preview', [CampaignController::class, 'preview'])->name('preview');
    Route::post('segment/count', [CampaignController::class, 'count'])->name('segment.count');

    Route::get('templates', [MailTemplateController::class, 'index'])->name('templates.index');
    Route::post('templates', [MailTemplateController::class, 'store'])->name('templates.store');
    Route::get('templates/{template}/edit', [MailTemplateController::class, 'edit'])->name('templates.edit');
    Route::put('templates/{template}', [MailTemplateController::class, 'update'])->name('templates.update');
    Route::delete('templates/{template}', [MailTemplateController::class, 'destroy'])->name('templates.destroy');

    Route::whereNumber('campaign')->group(function () {
        Route::get('{campaign}/edit', [CampaignController::class, 'edit'])->name('edit');
        Route::put('{campaign}', [CampaignController::class, 'update'])->name('update');
        Route::delete('{campaign}', [CampaignController::class, 'destroy'])->name('destroy');
        Route::post('{campaign}/test', [CampaignController::class, 'test'])->middleware('throttle:10,1')->name('test');
        Route::post('{campaign}/launch', [CampaignController::class, 'launch'])->name('launch');
        Route::post('{campaign}/pause', [CampaignController::class, 'pause'])->name('pause');
        Route::post('{campaign}/resume', [CampaignController::class, 'resume'])->name('resume');
        Route::post('{campaign}/cancel', [CampaignController::class, 'cancel'])->name('cancel');
        Route::post('{campaign}/retry', [CampaignController::class, 'retry'])->name('retry');
        Route::post('{campaign}/duplicate', [CampaignController::class, 'duplicate'])->name('duplicate');
    });
});

Route::middleware(['auth', Roles::middleware(Roles::CAMPAIGN_VIEWERS)])->prefix('campaigns')->name('campaigns.')->group(function () {
    Route::get('/', [CampaignController::class, 'index'])->name('index');
    Route::get('{campaign}', [CampaignController::class, 'show'])->whereNumber('campaign')->name('show');
});
