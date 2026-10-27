<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CouncilSettingsController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'chairman_model' => ['sometimes', 'string', 'max:200'],
            'chairman_use_openrouter' => ['sometimes', 'boolean'],
            'enable_peer_review' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['chairman_use_openrouter']) && ! isset($data['chairman_model'])) {
            $isOpenRouterModel = str_contains(Setting::read('council')['chairman_model'], '/');

            if ($data['chairman_use_openrouter'] && ! $isOpenRouterModel) {
                $data['chairman_model'] = Setting::read('defaults')['openrouter_model'];
            } elseif (! $data['chairman_use_openrouter'] && $isOpenRouterModel) {
                $data['chairman_model'] = config('agenthost.council.chairman_model');
            }
        }

        return response()->json(Setting::merge('council', $data));
    }
}
