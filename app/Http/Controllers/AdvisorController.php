<?php

namespace App\Http\Controllers;

use App\Models\Advisor;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AdvisorController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'prompt' => ['required', 'string', 'max:4000'],
            'model' => ['required', 'string', 'max:200'],
            'icon' => ['required', 'string', 'max:16'],
            'color' => ['required', 'hex_color'],
            'use_openrouter' => ['required', 'boolean'],
        ]);

        return response()->json(Advisor::create($data), 201);
    }

    public function update(Request $request, Advisor $advisor): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'prompt' => ['sometimes', 'string', 'max:4000'],
            'model' => ['sometimes', 'string', 'max:200'],
            'icon' => ['sometimes', 'string', 'max:16'],
            'color' => ['sometimes', 'hex_color'],
            'use_openrouter' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $advisor->fill($data);
        $defaults = Setting::read('defaults');
        $isOpenRouterModel = str_contains($advisor->model, '/');

        if ($advisor->use_openrouter && ! $isOpenRouterModel) {
            $advisor->model = $defaults['openrouter_model'];
        } elseif (! $advisor->use_openrouter && $isOpenRouterModel) {
            $advisor->model = $defaults['claude_model'];
        }

        $advisor->save();

        return response()->json($advisor);
    }

    public function destroy(Advisor $advisor): Response
    {
        $advisor->delete();

        return response()->noContent();
    }

    public function reset(): JsonResponse
    {
        Advisor::restoreDefaults();

        return response()->json(Advisor::orderBy('id')->get());
    }
}
