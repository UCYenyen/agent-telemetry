<?php

namespace App\Http\Controllers;

use App\Models\ConcordParticipant;
use App\Models\ConcordRoom;
use App\Models\ConcordRoomInvite;
use App\Models\User;
use App\Services\AgentSessionManager;
use App\Services\ConcordHubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConcordRoomController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            ConcordRoom::withCount(['participants', 'messages', 'invites', 'localTasks'])
                ->whereNotNull('agent_session_id')
                ->latest('updated_at')
                ->get()
        );
    }

    public function store(Request $request, ConcordHubService $hub): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:140'],
            'description' => ['nullable', 'string', 'max:1000'],
            'access_mode' => ['nullable', 'string', 'in:invite_only,link_view,link_edit'],
            'owner_email' => ['nullable', 'email'],
        ]);

        $sessionUser = $request->session()->get('agent_host.user');
        if (empty($data['owner_email']) && is_array($sessionUser) && ! empty($sessionUser['email'])) {
            $data['owner_email'] = $sessionUser['email'];
        }

        $room = $hub->createRoom($data);

        return response()->json($this->formatRoom($room, $request), 201);
    }

    public function show(Request $request, ConcordRoom $concordRoom): JsonResponse
    {
        return response()->json($this->formatRoom($concordRoom, $request));
    }

    public function update(Request $request, ConcordRoom $concordRoom): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:140'],
            'description' => ['nullable', 'string', 'max:1000'],
            'access_mode' => ['sometimes', 'string', 'in:invite_only,link_view,link_edit'],
        ]);

        $concordRoom->update($data);

        return response()->json($this->formatRoom($concordRoom->fresh(), $request));
    }

    public function destroy(Request $request, ConcordRoom $concordRoom, AgentSessionManager $manager): Response
    {
        $this->ensureAdmin($request);

        // A session's group and the session go together.
        if ($concordRoom->agentSession) {
            $manager->stop($concordRoom->agentSession);
        } else {
            $concordRoom->delete();
        }

        return response()->noContent();
    }

    public function storeInvite(Request $request, ConcordRoom $concordRoom): JsonResponse
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'email' => ['nullable', 'string', 'max:2000'],
            'name' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', 'string', 'in:team_member,no_role,editor,viewer'],
            'invites' => ['nullable', 'array'],
            'invites.*.email' => ['required_with:invites', 'string', 'max:180'],
            'invites.*.name' => ['nullable', 'string', 'max:120'],
            'invites.*.role' => ['nullable', 'string', 'in:team_member,no_role,editor,viewer'],
        ]);

        $entries = [];

        if (! empty($data['invites']) && is_array($data['invites'])) {
            foreach ($data['invites'] as $item) {
                $rawIdentifier = trim((string) ($item['email'] ?? ''));
                if ($rawIdentifier === '') {
                    continue;
                }
                $entries[] = [
                    'identifier' => $rawIdentifier,
                    'name' => $item['name'] ?? null,
                    'role' => $item['role'] ?? ($data['role'] ?? 'no_role'),
                ];
            }
        } elseif (! empty($data['email'])) {
            // Support comma, semicolon, or newline separated bulk list of emails or usernames
            $tokens = preg_split('/[\s,;]+/', trim((string) $data['email']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            foreach ($tokens as $tok) {
                $entries[] = [
                    'identifier' => $tok,
                    'name' => count($tokens) === 1 ? ($data['name'] ?? null) : null,
                    'role' => $data['role'] ?? 'no_role',
                ];
            }
        }

        if (empty($entries)) {
            return response()->json(['message' => 'Please provide at least one email or username to invite.'], 422);
        }

        $createdInvites = [];
        foreach ($entries as $entry) {
            $identifier = strtolower(trim($entry['identifier']));
            $matchedUser = User::query()
                ->whereRaw('LOWER(email) = ?', [$identifier])
                ->orWhereRaw('LOWER(username) = ?', [$identifier])
                ->orWhereRaw('LOWER(name) = ?', [$identifier])
                ->first();

            $resolvedEmail = $matchedUser ? strtolower($matchedUser->email) : $identifier;
            if (! str_contains($resolvedEmail, '@')) {
                $resolvedEmail = $resolvedEmail.'@member.local';
            }

            $resolvedName = $entry['name']
                ?: ($matchedUser?->name)
                ?: explode('@', $resolvedEmail)[0];

            $normalizedRole = match ($entry['role'] ?? 'no_role') {
                'editor', 'team_member' => 'team_member',
                default => 'no_role',
            };

            $createdInvites[] = $concordRoom->invites()->updateOrCreate(
                ['email' => $resolvedEmail],
                [
                    'name' => $resolvedName,
                    'role' => $normalizedRole,
                ]
            );
        }

        return response()->json([
            'invite' => $createdInvites[0] ?? null,
            'invites' => $createdInvites,
            'count' => count($createdInvites),
        ], 201);
    }

    public function updateInvite(Request $request, ConcordRoom $concordRoom, ConcordRoomInvite $invite): JsonResponse
    {
        $this->ensureAdmin($request);
        abort_unless($invite->concord_room_id === $concordRoom->id, 404);

        $data = $request->validate([
            'role' => ['required', 'string', 'in:team_member,no_role,editor,viewer'],
        ]);

        $normalizedRole = in_array($data['role'], ['team_member', 'editor'], true) ? 'team_member' : 'no_role';
        $invite->update(['role' => $normalizedRole]);

        return response()->json(['invite' => $invite->fresh()]);
    }

    public function destroyInvite(Request $request, ConcordRoom $concordRoom, ConcordRoomInvite $invite): Response
    {
        $this->ensureAdmin($request);
        abort_unless($invite->concord_room_id === $concordRoom->id, 404);
        $invite->delete();

        return response()->noContent();
    }

    public function storeParticipant(Request $request, ConcordRoom $concordRoom): JsonResponse
    {
        [$permission] = $this->resolveUserRoomPermission($request, $concordRoom);
        abort_unless($permission === 'edit', 403, 'Only admin or team_member can connect an active MCP agent in this project.');

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:120'],
            'agent_name' => ['nullable', 'string', 'max:120'],
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

        $agentName = $data['name'] ?? $data['agent_name'] ?? ($data['user_name'].' MCP Agent');

        $participant = $concordRoom->participants()->create([
            'kind' => 'agent',
            'name' => $agentName,
            'user_name' => $data['user_name'],
            'account_email' => $data['account_email'] ?? null,
            'device_name' => $data['device_name'],
            'execution_mode' => 'mcp_connected',
            'provider' => $data['provider'],
            'model' => $data['model'],
            'avatar' => $data['avatar'] ?? 'AG',
            'color' => $data['color'] ?? '#10b981',
            'role_prompt' => $data['role_prompt'] ?? "MCP Agent for {$data['user_name']} executing local code edits on {$data['device_name']}.",
            'use_openrouter' => (bool) ($data['use_openrouter'] ?? str_contains($data['model'], '/')),
            'status' => 'idle',
            'current_thought' => "MCP Connected to VPS Shared Memory from {$data['device_name']}",
            'current_action' => "MCP Ready on {$data['device_name']}",
            'thinking_steps' => ["MCP Handshake verified for {$agentName} on {$data['device_name']}"],
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
            'sender_icon' => 'MCP',
            'sender_color' => $participant->color,
            'model' => $participant->model,
            'kind' => 'system',
            'content' => "{$participant->user_name} connected MCP agent {$participant->name} (Device: {$participant->device_name}) to the shared context room. Prompting unlocked for {$participant->user_name}.",
        ]);

        return response()->json($participant, 201);
    }

    public function destroyParticipant(Request $request, ConcordRoom $concordRoom, ConcordParticipant $participant): Response
    {
        abort_unless($participant->concord_room_id === $concordRoom->id, 404);

        $sessionUser = $request->session()->get('agent_host.user');
        $isAdmin = is_array($sessionUser) && ($sessionUser['role'] ?? '') === 'admin';
        $userEmail = strtolower(trim((string) ($sessionUser['email'] ?? '')));
        $isOwnParticipant = $userEmail !== '' && strtolower((string) $participant->account_email) === $userEmail;

        abort_unless($isAdmin || $isOwnParticipant, 403, 'Only Admin or the owner of this MCP connection can disconnect it.');
        $participant->delete();

        return response()->noContent();
    }

    public function storeMessage(Request $request, ConcordRoom $concordRoom, ConcordHubService $hub): JsonResponse
    {
        [$permission] = $this->resolveUserRoomPermission($request, $concordRoom);
        abort_unless($permission === 'edit', 403, 'Your role in this project is no_role (view-only). Ask an Admin to grant team_member role or enable Anyone with link can edit.');

        $data = $request->validate([
            'content' => ['nullable', 'string', 'max:8000'],
            'body' => ['nullable', 'string', 'max:8000'],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'sender_user' => ['nullable', 'string', 'max:120'],
            'sender_email' => ['nullable', 'string', 'max:190'],
            'sender_device' => ['nullable', 'string', 'max:120'],
            'participant_id' => ['nullable', 'string'],
            'target_participant_id' => ['nullable', 'string'],
            'target_agent' => ['nullable', 'string'],
            'auto_handoff' => ['sometimes', 'boolean'],
        ]);

        $sessionUser = $request->session()->get('agent_host.user');
        $senderEmail = strtolower(trim((string) ($data['sender_email'] ?? $sessionUser['email'] ?? '')));
        $senderName = strtolower(trim((string) ($data['sender_name'] ?? $sessionUser['name'] ?? $sessionUser['username'] ?? '')));

        $connectedParticipant = null;
        if (! empty($data['participant_id'])) {
            $connectedParticipant = $concordRoom->participants()->whereKey($data['participant_id'])->first();
        }
        if (! $connectedParticipant && $senderEmail !== '') {
            $connectedParticipant = $concordRoom->participants()->whereRaw('LOWER(account_email) = ?', [$senderEmail])->first();
        }
        if (! $connectedParticipant && $senderName !== '') {
            $connectedParticipant = $concordRoom->participants()->whereRaw('LOWER(user_name) = ?', [$senderName])->first();
        }

        if (! $connectedParticipant) {
            return response()->json([
                'requires_mcp' => true,
                'message' => 'MCP Connection Required: You must connect your local MCP agent first before you can prompt in this Group Chat.',
            ], 422);
        }

        $data['sender_name'] = $connectedParticipant->user_name;
        $data['sender_user'] = $connectedParticipant->user_name;
        $data['sender_device'] = $connectedParticipant->device_name;

        $data['verified_email'] = $request->session()->get('agent_host.authenticated') ? ($sessionUser['email'] ?? null) : null;

        $updated = $hub->sendPrompt($concordRoom, $data);

        return response()->json($this->formatRoom($updated, $request), 201);
    }

    public function updateMemory(Request $request, ConcordRoom $concordRoom, ConcordHubService $hub): JsonResponse
    {
        [$permission] = $this->resolveUserRoomPermission($request, $concordRoom);
        abort_unless($permission === 'edit', 403, 'Only admin or team_member can update shared memory in this project.');

        if ($request->has('shared_memory') && is_array($request->input('shared_memory'))) {
            $concordRoom->update(['shared_memory' => $request->input('shared_memory')]);
            return response()->json($this->formatRoom($concordRoom->fresh(), $request));
        }

        $data = $request->validate([
            'key' => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:60'],
            'content' => ['required', 'string', 'max:4000'],
            'author_agent' => ['nullable', 'string', 'max:120'],
            'author_user' => ['nullable', 'string', 'max:120'],
        ]);

        $hub->upsertMemory($concordRoom, $data);

        return response()->json($this->formatRoom($concordRoom->fresh(), $request));
    }

    protected function ensureAdmin(Request $request): void
    {
        $sessionUser = $request->session()->get('agent_host.user');
        $role = is_array($sessionUser) ? ($sessionUser['role'] ?? 'admin') : 'admin';

        abort_unless($role === 'admin', 403, 'Forbidden: Only Admin users can create or manage Group Chat projects and member roles.');
    }

    /**
     * @return array{0: string, 1: string, 2: bool}
     */
    protected function resolveUserRoomPermission(Request $request, ConcordRoom $room): array
    {
        $sessionUser = $request->session()->get('agent_host.user');
        $globalRole = is_array($sessionUser) ? ($sessionUser['role'] ?? 'admin') : 'admin';

        if ($globalRole === 'admin') {
            return ['edit', 'admin', false];
        }

        // If room access_mode is "Anyone with the link can edit" (link_edit), role check is bypassed
        if ($room->access_mode === 'link_edit') {
            return ['edit', 'team_member', true];
        }

        $email = strtolower(trim((string) ($sessionUser['email'] ?? '')));
        $username = strtolower(trim((string) ($sessionUser['username'] ?? $sessionUser['name'] ?? '')));

        if ($email !== '' && strtolower((string) $room->owner_email) === $email) {
            return ['edit', 'admin', false];
        }

        $invite = $room->invites()
            ->where(function ($q) use ($email, $username) {
                if ($email !== '') {
                    $q->orWhereRaw('LOWER(email) = ?', [$email]);
                }
                if ($username !== '') {
                    $q->orWhereRaw('LOWER(email) = ?', [$username])
                        ->orWhereRaw('LOWER(name) = ?', [$username]);
                }
            })
            ->first();

        if ($invite && in_array($invite->role, ['team_member', 'editor', 'admin'], true)) {
            return ['edit', 'team_member', false];
        }

        return ['view', 'no_role', false];
    }

    private function formatRoom(ConcordRoom $room, Request $request): array
    {
        [$permission, $projectRole, $bypassedByLink] = $this->resolveUserRoomPermission($request, $room);
        $sessionUser = $request->session()->get('agent_host.user');
        $globalRole = is_array($sessionUser) ? ($sessionUser['role'] ?? 'admin') : 'admin';

        $room->load([
            'participants' => fn ($q) => $q->orderBy('created_at'),
            'invites' => fn ($q) => $q->orderBy('created_at'),
            'messages' => fn ($q) => $q->orderBy('id'),
            'localTasks' => fn ($q) => $q->latest('created_at')->limit(25),
        ]);

        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        $platformUsers = $globalRole === 'admin'
            ? User::query()->orderBy('id')->get(['id', 'name', 'username', 'email', 'phone', 'role'])->toArray()
            : [];

        return [
            ...$room->toArray(),
            'permission' => $permission,
            'project_role' => $projectRole,
            'global_role' => $globalRole,
            'can_manage_room' => $globalRole === 'admin',
            'role_bypassed_by_link' => $bypassedByLink,
            'roles_catalog' => ConcordHubService::rolesCatalog(),
            'platform_users' => $platformUsers,
            'share_url' => $baseUrl.'/?room='.$room->share_token,
            'mcp_endpoint' => $baseUrl.'/api/concord/mcp/'.$room->mcp_token,
            'connect_command' => 'curl -fsSL '.$baseUrl.'/connect/'.$room->share_token.' | bash',
            'local_tasks' => $room->localTasks->values(),
        ];
    }
}
