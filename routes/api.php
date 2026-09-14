<?php

use App\Http\Controllers\Api\ContextApiController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'context.token', 'throttle:context'])->group(function () {
    // Memory endpoints
    Route::post('/memory/search', [ContextApiController::class, 'searchMemory']);
    Route::post('/memory/store', [ContextApiController::class, 'storeMemory']);

    // Context pack endpoints
    Route::post('/context', [ContextApiController::class, 'buildContext']);
    Route::post('/context/debug', [ContextApiController::class, 'debugContext']);
    Route::get('/projects/{project}/context', [ContextApiController::class, 'getProjectContext']);

    // Document endpoints
    Route::post('/documents', [ContextApiController::class, 'storeDocument']);

    // Conversation and message endpoints
    Route::post('/conversations', [ContextApiController::class, 'storeConversation']);
    Route::post('/conversations/{conversation}/messages', [ContextApiController::class, 'appendMessage']);
    Route::get('/projects/{project}/conversations/{conversation}/messages', [ContextApiController::class, 'getRecentMessages']);
});
