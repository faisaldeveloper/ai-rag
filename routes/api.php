<?php

use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\SearchController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('api.health');

Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('documents', DocumentController::class)->except('update');
    Route::post('documents/{document}/retry', [DocumentController::class, 'retry'])->name('documents.retry');
    Route::post('search', SearchController::class)->middleware('throttle:search')->name('search');
    Route::post('chat', ChatController::class)->middleware('throttle:chat')->name('chat');
    Route::apiResource('conversations', ConversationController::class)->only(['index', 'show']);
});
