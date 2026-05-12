<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EditorController;
use App\Http\Controllers\InspectorController;
use App\Http\Controllers\InstanceController;
use App\Http\Controllers\InstituteController;
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

    Route::get('/instances/{slug}/instituto',  [InstituteController::class, 'edit'])
        ->name('instances.institute');
    Route::put('/instances/{slug}/instituto',  [InstituteController::class, 'update'])
        ->name('instances.institute.update');
    Route::post('/instances/{slug}/rebuild',   [InstituteController::class, 'rebuild'])
        ->name('instances.rebuild');
    Route::get('/instances/{slug}/rebuild/log', [InstituteController::class, 'log'])
        ->name('instances.rebuild.log');

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

    // ── Editor de datos (usuarios y declaraciones) ─────────────
    Route::get('/instances/{slug}/users/{id}/edit', [EditorController::class, 'editUser'])
        ->where('id', '[a-f0-9]{24}')
        ->name('users.edit');
    Route::put('/instances/{slug}/users/{id}', [EditorController::class, 'updateUser'])
        ->where('id', '[a-f0-9]{24}')
        ->name('users.update');
    Route::put('/instances/{slug}/users/{id}/password', [EditorController::class, 'resetPassword'])
        ->where('id', '[a-f0-9]{24}')
        ->name('users.password');
    Route::put('/instances/{slug}/users/{id}/roles', [EditorController::class, 'updateRoles'])
        ->where('id', '[a-f0-9]{24}')
        ->name('users.roles');

    Route::put('/instances/{slug}/declaraciones/{id}/fecha', [EditorController::class, 'updateDeclaracionFecha'])
        ->where('id', '[a-f0-9]{24}')
        ->name('declaraciones.fecha');
    Route::delete('/instances/{slug}/declaraciones/{id}', [EditorController::class, 'deleteDeclaracion'])
        ->where('id', '[a-f0-9]{24}')
        ->name('declaraciones.destroy');
});
