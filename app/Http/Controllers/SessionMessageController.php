<?php

namespace App\Http\Controllers;

use App\Models\AgentSession;
use App\Services\AgentSessionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class SessionMessageController extends Controller
{
    public function store(Request $request, AgentSession $agentSession, AgentSessionManager $manager): JsonResponse
    {
        $text = $request->validate(['text' => ['required', 'string']])['text'];

        if (! $manager->prompt($agentSession, $text)) {
            return response()->json(['message' => 'Failed to send keystrokes to tmux'], 502);
        }

        return response()->json(['sent' => true], 201);
    }

    public function destroy(AgentSession $agentSession): Response
    {
        $agentSession->messages()->delete();

        return response()->noContent();
    }
}
