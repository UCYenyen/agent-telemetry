<?php

use App\Http\Controllers\AdvisorController;
use App\Http\Controllers\AgentSessionController;
use App\Http\Controllers\ConcordRoomController;
use App\Http\Controllers\ConcordSharedController;
use App\Http\Controllers\CouncilRoomController;
use App\Http\Controllers\CouncilSettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FallbackController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\OpenRouterModelController;
use App\Http\Controllers\SessionMessageController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StateController;
use App\Http\Middleware\RequireAdmin;
use App\Http\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::get('login', [LoginController::class, 'create'])->name('login');
Route::post('login', [LoginController::class, 'store'])->middleware('throttle:5,1');
Route::get('register', [LoginController::class, 'create'])->name('register');
Route::post('register', [LoginController::class, 'register'])->middleware('throttle:10,1');
Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

Route::get('connect/{token}', [ConcordSharedController::class, 'connectorScript']);

Route::prefix('api/concord')->group(function () {
    Route::get('shared/{token}', [ConcordSharedController::class, 'show']);
    Route::post('shared/{token}/messages', [ConcordSharedController::class, 'storeMessage']);
    Route::post('shared/{token}/register-device', [ConcordSharedController::class, 'registerDevice']);
    Route::delete('shared/{token}/participants/{participant}', [ConcordSharedController::class, 'destroyParticipant']);
    Route::post('shared/{token}/heartbeat', [ConcordSharedController::class, 'heartbeat']);
    Route::get('shared/{token}/tasks/pull', [ConcordSharedController::class, 'pullTask']);
    Route::post('shared/{token}/tasks/{task}/complete', [ConcordSharedController::class, 'completeTask']);
    Route::post('mcp/{mcpToken}', [ConcordSharedController::class, 'mcp']);
    Route::post('mcp/{mcpToken}/events', [ConcordSharedController::class, 'mcpEvent']);
    Route::post('mcp/{mcpToken}/inbox', [ConcordSharedController::class, 'mcpInbox']);
    Route::match(['get', 'delete'], 'mcp/{mcpToken}', [ConcordSharedController::class, 'mcpMethodNotAllowed']);
});

Route::middleware(RequirePassword::class)->group(function () {
    Route::get('/', DashboardController::class);

    Route::prefix('api')->group(function () {
        Route::get('state', StateController::class);

        // Admin-only routes: Live VPS Claude / Antigravity Fleet Sessions, Failover Settings, and Council
        Route::middleware(RequireAdmin::class)->group(function () {
            Route::apiResource('sessions', AgentSessionController::class)
                ->only(['store', 'show', 'update', 'destroy'])
                ->parameters(['sessions' => 'agentSession']);
            Route::post('sessions/{agentSession}/messages', [SessionMessageController::class, 'store']);
            Route::delete('sessions/{agentSession}/messages', [SessionMessageController::class, 'destroy']);

            Route::patch('settings', [SettingsController::class, 'update']);
            Route::post('openrouter/models', [OpenRouterModelController::class, 'store']);
            Route::apiResource('fallbacks', FallbackController::class)->only(['store', 'update', 'destroy']);

            Route::post('advisors/reset', [AdvisorController::class, 'reset']);
            Route::apiResource('advisors', AdvisorController::class)->only(['store', 'update', 'destroy']);
            Route::patch('council/settings', [CouncilSettingsController::class, 'update']);
            Route::apiResource('council/rooms', CouncilRoomController::class)
                ->only(['store', 'show', 'destroy'])
                ->parameters(['rooms' => 'councilRoom']);
        });

        // Concord Group Chat routes (role enforcement handled per-project inside ConcordRoomController)
        Route::get('concord/rooms', [ConcordRoomController::class, 'index']);
        Route::post('concord/rooms', [ConcordRoomController::class, 'store']);
        Route::get('concord/rooms/{concordRoom}', [ConcordRoomController::class, 'show']);
        Route::patch('concord/rooms/{concordRoom}', [ConcordRoomController::class, 'update']);
        Route::delete('concord/rooms/{concordRoom}', [ConcordRoomController::class, 'destroy']);
        Route::post('concord/rooms/{concordRoom}/invites', [ConcordRoomController::class, 'storeInvite']);
        Route::patch('concord/rooms/{concordRoom}/invites/{invite}', [ConcordRoomController::class, 'updateInvite']);
        Route::delete('concord/rooms/{concordRoom}/invites/{invite}', [ConcordRoomController::class, 'destroyInvite']);
        Route::post('concord/rooms/{concordRoom}/participants', [ConcordRoomController::class, 'storeParticipant']);
        Route::delete('concord/rooms/{concordRoom}/participants/{participant}', [ConcordRoomController::class, 'destroyParticipant']);
        Route::post('concord/rooms/{concordRoom}/messages', [ConcordRoomController::class, 'storeMessage']);
        Route::patch('concord/rooms/{concordRoom}/memory', [ConcordRoomController::class, 'updateMemory']);
    });
});
