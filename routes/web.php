<?php

use App\Http\Controllers\DocumentDownloadController;
use App\Http\Controllers\LocaleController;
use App\Http\Middleware\ApplyUserLocale;
use App\Http\Middleware\EnsureAccountUsable;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

Route::middleware(['auth', EnsureAccountUsable::class, ApplyUserLocale::class])->group(function (): void {
    Route::get('/documents/{document:uuid}/download', DocumentDownloadController::class)
        ->name('documents.download');

    Route::get('/locale/{locale}', LocaleController::class)
        ->whereIn('locale', ['ar', 'en'])
        ->name('locale.switch');
});
