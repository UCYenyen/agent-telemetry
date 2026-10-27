<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\OpenRouter;
use Illuminate\Http\JsonResponse;

class OpenRouterModelController extends Controller
{
    public function store(OpenRouter $openRouter): JsonResponse
    {
        $models = $openRouter->models($openRouter->keyFor());

        Setting::merge('models', ['openrouter' => $models]);

        return response()->json(['models' => $models]);
    }
}
