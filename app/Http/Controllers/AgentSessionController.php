<?php

namespace App\Http\Controllers;

use App\Models\AgentSession;
use App\Services\AgentSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class AgentSessionController extends Controller
{
    public function __construct(private AgentSessionManager $manager) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'agent' => ['required', Rule::in(['auto', 'claude', 'antigravity', 'openrouter'])],
            'model' => ['nullable', 'string', 'max:200'],
            'workdir' => ['required', 'string', 'max:500'],
            'use_openrouter' => ['boolean'],
        ]);

        $session = $this->manager->start(
            $data['title'],
            $data['agent'],
            $data['model'] ?? null,
            $data['workdir'],
            $data['use_openrouter'] ?? false,
            (string) data_get($request->session()->get('agent_host.user'), 'email', ''),
        );

        return response()->json($session, 201);
    }

    public function show(AgentSession $agentSession): JsonResponse
    {
        return response()->json($this->manager->history($agentSession));
    }

    public function update(Request $request, AgentSession $agentSession): JsonResponse
    {
        $data = $request->validate([
            'model' => ['sometimes', 'string', 'max:200'],
            'use_openrouter' => ['sometimes', 'boolean'],
            'fallback_enabled' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['model'])) {
            $this->manager->switchModel($agentSession, $data['model']);
        }

        if (isset($data['use_openrouter'])) {
            $this->manager->toggleOpenRouter($agentSession, $data['use_openrouter']);
        }

        if (isset($data['fallback_enabled'])) {
            $agentSession->update(['fallback_enabled' => $data['fallback_enabled']]);
        }

        return response()->json($agentSession);
    }

    public function destroy(AgentSession $agentSession): Response
    {
        $this->manager->stop($agentSession);

        return response()->noContent();
    }
}
