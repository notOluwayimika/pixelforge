<?php

use App\Http\Controllers\Admin\UsageReportController;
use App\Http\Controllers\CreditsController;
use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    Route::redirect('dashboard', '/generations')->name('dashboard');

    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::get('generations/new', [GenerationController::class, 'create'])->name('generations.create');
    Route::post('generations/estimate', [GenerationController::class, 'estimate'])->name('generations.estimate');
    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:20,1')
        ->name('generations.store');
    Route::post('generations/{generation}/retry', [GenerationController::class, 'retry'])
        ->middleware('throttle:20,1')
        ->name('generations.retry');

    Route::get('credits', [CreditsController::class, 'index'])->name('credits.index');
    Route::post('credits/purchase', [CreditsController::class, 'purchase'])
        ->middleware('throttle:20,1')
        ->name('credits.purchase');

    Route::get('admin/report', UsageReportController::class)
        ->middleware('can:view-admin-report')
        ->name('admin.report');
});

require __DIR__.'/settings.php';
