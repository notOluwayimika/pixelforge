<?php

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
});

require __DIR__.'/settings.php';
