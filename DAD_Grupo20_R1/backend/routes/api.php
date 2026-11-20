<?php

use App\Http\Controllers\AuthController;
use Illuminate\Support\Facades\Route;

// R1: endpoints da entrega intermédia.
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});

// Compatibilidade com os endpoints ensinados nos tutoriais.
Route::post('/login', [AuthController::class, 'loginLegacy'])->middleware('throttle:5,1');
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/users/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
});
