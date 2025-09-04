<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\UploadController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('upload.form');
});

// Auth routes
Route::get('/login', [LoginController::class, 'showLogin'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// Upload routes
Route::middleware('simpleauth')->group(function () {
    Route::get('/upload', [UploadController::class, 'form'])->name('upload.form');
    Route::post('/upload', [UploadController::class, 'process'])->name('upload.process');
});
