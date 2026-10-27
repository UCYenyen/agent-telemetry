<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent' => ['sometimes', Rule::in(['auto', 'claude', 'antigravity', 'openrouter'])],
            'claude_model' => ['sometimes', 'string', 'max:200'],
            'workdir' => ['sometimes', 'string', 'max:500'],
            'fallback_enabled' => ['sometimes', 'boolean'],
            'openrouter_api_key' => ['nullable', 'string', 'max:500'],
        ]);

        $key = trim(Arr::pull($data, 'openrouter_api_key') ?? '');

        if ($key !== '' && ! str_contains($key, '••••')) {
            Setting::merge('openrouter', ['api_key' => $key]);
        }

        return response()->json(Setting::merge('defaults', $data));
    }
}
