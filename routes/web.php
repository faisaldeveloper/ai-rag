<?php

use App\Http\Controllers\Auth\LoginController;
use App\Models\Document;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/chat');

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::inertia('chat', 'Chat')->name('chat.index');

    Route::get('knowledge-base', fn () => inertia('KnowledgeBase', [
        'maxUploadKilobytes' => (int) config('rag.max_upload_kilobytes'),
    ]))->can('viewAny', Document::class)->name('knowledge-base');
});
