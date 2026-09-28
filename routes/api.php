<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TaskController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:5,1');

Route::get('/tasks', [TaskController::class, 'index']);
Route::post('/tasks', [TaskController::class, 'store']);

Route::get('/tasks/{id}', [TaskController::class, 'show']);

Route::patch('/tasks/{id}/status', [TaskController::class, 'updateStatus'])
    ->middleware('auth:sanctum');
