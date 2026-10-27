<?php

namespace App\Http\Controllers;

use App\Models\Advisor;
use App\Models\AgentSession;
use App\Models\CouncilRoom;
use App\Models\Fallback;
use App\Models\Setting;
use App\Services\OpenRouter;
use Illuminate\Http\JsonResponse;

class StateController extends Controller
{
    public function __invoke(OpenRouter $openRouter): JsonResponse
    {
        $globalKey = trim(Setting::read('openrouter')['api_key'] ?? '');
        $effectiveKey = $openRouter->keyFor();

        $fallbacks = Fallback::chain()->map(function (Fallback $fallback) use ($globalKey) {
            $key = trim($fallback->api_key ?? '') ?: $globalKey;

            return [
                'id' => $fallback->id,
                'provider' => $fallback->provider,
                'model' => $fallback->model,
                'has_key' => $key !== '',
                'masked_key' => OpenRouter::mask($key),
            ];
        });

        return response()->json([
            'sessions' => AgentSession::withCount('messages')->oldest()->get(),
            'defaults' => Setting::read('defaults'),
            'models' => Setting::read('models'),
            'fallbacks' => $fallbacks,
            'openrouter' => [
                'has_key' => $effectiveKey !== '',
                'masked_key' => OpenRouter::mask($effectiveKey, '••••••••'),
            ],
            'council' => [
                ...Setting::read('council'),
                'advisors' => Advisor::orderBy('id')->get(),
                'rooms' => CouncilRoom::select(['id', 'title', 'status', 'updated_at'])->withCount('messages')->latest('updated_at')->get(),
            ],
        ]);
    }
}
