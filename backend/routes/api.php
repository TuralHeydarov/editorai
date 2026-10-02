<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProjectController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — AI Video Editor
|--------------------------------------------------------------------------
*/

Route::prefix('api')->group(function () {

    // --- Auth (Public) ---
    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('legacy.login');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('legacy.login');

    // --- Protected Routes ---
    Route::middleware(['sso.api', 'auth:sanctum', 'project.owner'])->group(function () {

        // Auth
        Route::get('/auth/user', [AuthController::class, 'user']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Projects
        Route::post('/projects', [ProjectController::class, 'store']);
        Route::get('/projects', [ProjectController::class, 'index']);
        Route::get('/projects/{project}', [ProjectController::class, 'show']);
        Route::get('/projects/{project}/status', [ProjectController::class, 'checkStatus']);

        Route::post('/projects/{project}/media-session', [\App\Http\Controllers\Api\ProjectMediaController::class, 'session'])->middleware('throttle:30,1');

        // AI Analysis & Chat
        Route::post('/projects/{project}/analyze', [ProjectController::class, 'analyze']);
        Route::post('/projects/{project}/chat', [ProjectController::class, 'chat']);
        Route::get('/projects/{project}/transcription-status', [ProjectController::class, 'transcriptionStatus']);

        // Clips
        Route::put('/projects/{project}/clips/{clip}', [ProjectController::class, 'updateClip']);
        Route::delete('/projects/{project}/clips/{clip}', [ProjectController::class, 'deleteClip']);
        Route::post('/projects/{project}/clips/{clip}/broll', [ProjectController::class, 'refreshBroll']);

        // Render
        Route::post('/projects/{project}/render', [ProjectController::class, 'render']);

        // Timeline save
        Route::post('/projects/{project}/save-timeline', [ProjectController::class, 'saveTimeline']);
    });
});
