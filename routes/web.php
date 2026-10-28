<?php

use App\Http\Controllers\AdvisorController;
use App\Http\Controllers\AgentSessionController;
use App\Http\Controllers\CouncilRoomController;
use App\Http\Controllers\CouncilSettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FallbackController;
use App\Http\Controllers\LoginController;
use App\Http\Controllers\OpenRouterModelController;
use App\Http\Controllers\SessionMessageController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StateController;
use App\Http\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::get('login', [LoginController::class, 'create'])->name('login');
Route::post('login', [LoginController::class, 'store'])->middleware('throttle:5,1');
Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

Route::middleware(RequirePassword::class)->group(function () {
    Route::get('/', DashboardController::class);

    Route::prefix('api')->group(function () {
        Route::get('state', StateController::class);

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
});
