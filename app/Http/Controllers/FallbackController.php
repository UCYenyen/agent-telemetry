<?php

namespace App\Http\Controllers;

use App\Models\Fallback;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class FallbackController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', Rule::in(['openrouter', 'antigravity', 'claude'])],
            'model' => ['required', 'string', 'max:200'],
            'api_key' => ['nullable', 'string', 'max:500'],
        ]);

        $key = trim($data['api_key'] ?? '');
        $key = str_contains($key, '••••') ? '' : $key;

        $fallback = Fallback::create([
            'provider' => $data['provider'],
            'model' => trim($data['model']),
            'api_key' => $key ?: null,
            'position' => (Fallback::max('position') ?? -1) + 1,
        ]);

        if ($key !== '') {
            Setting::merge('openrouter', ['api_key' => $key]);
        }

        $this->syncDefaultModels();

        return response()->json($fallback, 201);
    }

    public function update(Request $request, Fallback $fallback): Response
    {
        $direction = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]])['direction'];

        $ids = Fallback::chain()->modelKeys();
        $index = array_search($fallback->id, $ids, true);
        $target = $direction === 'up' ? $index - 1 : $index + 1;

        if (isset($ids[$target])) {
            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

            foreach ($ids as $position => $id) {
                Fallback::whereKey($id)->update(['position' => $position]);
            }
        }

        return response()->noContent();
    }

    public function destroy(Fallback $fallback): Response
    {
        $fallback->delete();

        return response()->noContent();
    }

    private function syncDefaultModels(): void
    {
        $chain = Fallback::chain();
        $openRouter = $chain->where('provider', 'openrouter')->pluck('model');
        $antigravity = $chain->where('provider', 'antigravity')->pluck('model');

        if ($openRouter->isNotEmpty()) {
            Setting::merge('openrouter', ['fallback_models' => $openRouter->all()]);
            Setting::merge('defaults', ['openrouter_model' => $openRouter->first()]);
        }

        if ($antigravity->isNotEmpty()) {
            Setting::merge('defaults', ['antigravity_model' => $antigravity->first()]);
        }
    }
}
