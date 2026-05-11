<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InspectorController;
use App\Http\Controllers\InstanceController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'store'])->name('login.store');
Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

Route::middleware('admin')->group(function () {
    Route::get('/', DashboardController::class)->name('dashboard');

    Route::get('/password',  [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('/password',  [PasswordController::class, 'update'])->name('password.update');

    Route::get('/instances/{slug}', [InstanceController::class, 'show'])
        ->name('instances.show');
    Route::get('/instances/{slug}/inspect', [InspectorController::class, 'show'])
        ->name('instances.inspect');

    Route::post('/instances/{slug}/backups', [BackupController::class, 'store'])
        ->name('backups.store');
    Route::post('/instances/{slug}/upload', [BackupController::class, 'upload'])
        ->name('backups.upload');

    Route::get('/backups/{id}/download', [BackupController::class, 'download'])
        ->name('backups.download');
    Route::post('/backups/{id}/restore', [BackupController::class, 'restore'])
        ->name('backups.restore');
    Route::delete('/backups/{id}', [BackupController::class, 'destroy'])
        ->name('backups.destroy');
});
