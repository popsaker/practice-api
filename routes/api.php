<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TaskController;

Route::get('/tasks/{id}', [TaskController::class, 'show']);

Route::patch('/tasks/{id}/status', [TaskController::class, 'updateStatus']);
