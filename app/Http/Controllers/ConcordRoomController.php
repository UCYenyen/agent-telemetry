<?php

namespace App\Http\Controllers;

use App\Models\ConcordParticipant;
use App\Models\ConcordRoom;
use App\Models\ConcordRoomInvite;
use App\Services\ConcordHubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConcordRoomController extends Controller
{
    public function index(ConcordHubService $hub): JsonResponse
    {
        $hub->ensureDefaultRoom();

        return response()->json(
            ConcordRoom::withCount(['participants', 'messages', 'invites', 'localTasks'])
                ->latest('updated_at')
                ->get()
        );
    }

    public function store(Request $request, ConcordHubService $hub): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:140'],
            'description' => ['nullable', 'string', 'max:1000'],
            'access_mode' => ['nullable', 'string', 'in:invite_only,link_view,link_edit'],
            'owner_email' => ['nullable', 'email'],
        ]);

        return response()->json($hub->createRoom($data), 201);
    }

    public function show(ConcordRoom $concordRoom): JsonResponse
    {
        return response()->json($this->formatRoom($concordRoom));
    }

    public function update(Request $request, ConcordRoom $concordRoom): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:140'],
            'description' => ['nullable', 'string', 'max:1000'],
            'access_mode' => ['sometimes', 'string', 'in:invite_only,link_view,link_edit'],
        ]);

        $concordRoom->update($data);

        return response()->json($this->formatRoom($concordRoom->fresh()));
    }

    public function destroy(ConcordRoom $concordRoom): Response
    {
        $concordRoom->delete();

        return response()->noContent();
    }

    public function storeInvite(Request $request, ConcordRoom $concordRoom): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:180'],
            'name' => ['nullable', 'string', 'max:120'],
            'role' => ['required', 'string', 'in:viewer,editor'],
        ]);

        $invite = $concordRoom->invites()->updateOrCreate(
            ['email' => strtolower(trim($data['email']))],
            [
                'name' => $data['name'] ?? explode('@', $data['email'])[0],
                'role' => $data['role'],
            ]
        );

        return response()->json($invite, 201);
    }

    public function destroyInvite(ConcordRoom $concordRoom, ConcordRoomInvite $invite): Response
    {
        abort_unless($invite->concord_room_id === $concordRoom->id, 404);
        $invite->delete();

        return response()->noContent();
    }

    public function storeParticipant(Request $request, ConcordRoom $concordRoom): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'user_name' => ['required', 'string', 'max:120'],
            'account_email' => ['nullable', 'string', 'max:180'],
            'device_name' => ['required', 'string', 'max:120'],
            'provider' => ['required', 'string', 'max:40'],
            'model' => ['required', 'string', 'max:120'],
            'avatar' => ['nullable', 'string', 'max:16'],
            'color' => ['nullable', 'string', 'max:24'],
            'role_prompt' => ['nullable', 'string', 'max:1000'],
            'use_openrouter' => ['sometimes', 'boolean'],
        ]);

        $participant = $concordRoom->participants()->create([
            'kind' => 'agent',
            'name' => $data['name'],
            'user_name' => $data['user_name'],
            'account_email' => $data['account_email'] ?? null,
            'device_name' => $data['device_name'],
            'execution_mode' => 'local_device',
            'provider' => $data['provider'],
            'model' => $data['model'],
            'avatar' => $data['avatar'] ?? '✦',
            'color' => $data['color'] ?? '#ff5722',
            'role_prompt' => $data['role_prompt'] ?? "AI Agent for {$data['user_name']} executing local code edits on {$data['device_name']}.",
            'use_openrouter' => (bool) ($data['use_openrouter'] ?? str_contains($data['model'], '/')),
            'status' => 'idle',
            'current_thought' => "Connected to VPS Shared Memory from {$data['device_name']}",
            'current_action' => "Idle on {$data['device_name']}",
            'thinking_steps' => ["Registered {$data['name']} on {$data['device_name']}"],
            'claimed_files' => [],
            'local_modified_files' => [],
            'last_seen_at' => now(),
        ]);

        $concordRoom->messages()->create([
            'participant_id' => $participant->id,
            'sender_name' => $participant->name,
            'sender_user' => $participant->user_name,
            'sender_device' => $participant->device_name,
            'sender_kind' => $participant->provider,
            'sender_icon' => $participant->avatar,
            'sender_color' => $participant->color,
            'model' => $participant->model,
            'kind' => 'system',
            'content' => "{$participant->user_name} connected local agent {$participant->name} (Device: {$participant->device_name}) to the shared context room.",
        ]);

        return response()->json($participant, 201);
    }

    public function destroyParticipant(ConcordRoom $concordRoom, ConcordParticipant $participant): Response
    {
        abort_unless($participant->concord_room_id === $concordRoom->id, 404);
        $participant->delete();

        return response()->noContent();
    }

    public function storeMessage(Request $request, ConcordRoom $concordRoom, ConcordHubService $hub): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string', 'max:8000'],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'sender_user' => ['nullable', 'string', 'max:120'],
            'sender_device' => ['nullable', 'string', 'max:120'],
            'target_participant_id' => ['nullable', 'string'],
            'auto_handoff' => ['sometimes', 'boolean'],
        ]);

        $updated = $hub->sendPrompt($concordRoom, $data);

        return response()->json($this->formatRoom($updated), 201);
    }

    public function storeMemory(Request $request, ConcordRoom $concordRoom, ConcordHubService $hub): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'content' => ['required', 'string', 'max:4000'],
            'author_agent' => ['nullable', 'string', 'max:120'],
            'author_user' => ['nullable', 'string', 'max:120'],
        ]);

        $hub->upsertMemory($concordRoom, $data);

        return response()->json($this->formatRoom($concordRoom->fresh()), 201);
    }

    private function formatRoom(ConcordRoom $room): array
    {
        $room->load([
            'participants' => fn ($q) => $q->orderBy('created_at'),
            'invites' => fn ($q) => $q->orderBy('created_at'),
            'messages' => fn ($q) => $q->orderBy('id'),
            'localTasks' => fn ($q) => $q->latest('created_at')->limit(25),
        ]);

        return [
            ...$room->toArray(),
            'permission' => 'edit',
        ];
    }
}
