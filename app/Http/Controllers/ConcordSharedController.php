<?php

namespace App\Http\Controllers;

use App\Models\ConcordLocalTask;
use App\Models\ConcordParticipant;
use App\Models\ConcordRoom;
use App\Models\ConcordRoomInvite;
use App\Models\User;
use App\Services\ConcordHubService;
use App\Services\ConcordMcpServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ConcordSharedController extends Controller
{
    public function show(Request $request, string $token): JsonResponse
    {
        [$room, $invite, $permission, $projectRole, $bypassedByLink, $viewerEmail] = $this->resolveRoomAccess($request, $token);
        if (! $room) {
            return response()->json([
                'message' => 'Shared room or invite link not found.',
            ], 404);
        }

        if ($permission === 'forbidden') {
            return response()->json([
                'requires_email' => true,
                'title' => $room->title,
                'access_mode' => $room->access_mode,
                'roles_catalog' => ConcordHubService::rolesCatalog(),
                'message' => 'This group chat project is restricted (invite-only). Enter your invited email address to join.',
            ], 403);
        }

        if ($invite && ! $invite->accepted_at) {
            $invite->update(['accepted_at' => now()]);
        }

        return response()->json($this->formatSharedRoom($room, $permission, $projectRole, $bypassedByLink, $viewerEmail, $invite, $request));
    }

    public function storeMessage(Request $request, string $token, ConcordHubService $hub): JsonResponse
    {
        [$room, $invite, $permission, $projectRole, $bypassedByLink, $viewerEmail] = $this->resolveRoomAccess($request, $token);
        if (! $room) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        if ($permission !== 'edit') {
            return response()->json([
                'message' => 'Your project role is no_role (view-only). Only admin or team_member can send prompts or queue local edits unless Anyone with link can edit is enabled.',
            ], 403);
        }

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:12000'],
            'content' => ['nullable', 'string', 'max:12000'],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'sender_email' => ['nullable', 'string', 'max:190'],
            'sender_device' => ['nullable', 'string', 'max:160'],
            'target_agent' => ['nullable', 'string', 'max:120'],
            'target_participant_id' => ['nullable', 'string', 'max:120'],
            'participant_id' => ['nullable', 'string'],
        ]);

        $text = trim((string) ($validated['content'] ?? $validated['body'] ?? ''));
        if ($text === '') {
            return response()->json(['message' => 'Prompt content is required.'], 422);
        }

        $sessionUser = $request->session()->get('agent_host.user');
        $lookupEmail = strtolower(trim((string) ($validated['sender_email'] ?? $viewerEmail ?? $sessionUser['email'] ?? '')));
        $lookupName = strtolower(trim((string) ($validated['sender_name'] ?? $sessionUser['name'] ?? $sessionUser['username'] ?? '')));

        $participant = null;
        if (! empty($validated['participant_id'])) {
            $participant = $room->participants()->whereKey($validated['participant_id'])->first();
        }
        if (! $participant && $lookupEmail !== '') {
            $participant = $room->participants()->whereRaw('LOWER(account_email) = ?', [$lookupEmail])->first();
        }
        if (! $participant && $lookupName !== '') {
            $participant = $room->participants()->whereRaw('LOWER(user_name) = ?', [$lookupName])->first();
        }

        if (! $participant) {
            return response()->json([
                'requires_mcp' => true,
                'message' => 'MCP Connection Required: You must connect your local MCP agent first before you can prompt in this Group Chat.',
            ], 422);
        }

        $validated['sender_name'] = $participant->user_name;
        $validated['sender_user'] = $participant->user_name;
        $validated['sender_device'] = $participant->device_name;
        $validated['content'] = $text;
        $validated['verified_email'] = $request->session()->get('agent_host.authenticated') ? ($sessionUser['email'] ?? null) : null;

        $hub->sendPrompt($room, $validated);

        return response()->json($this->formatSharedRoom($room->fresh(), $permission, $projectRole, $bypassedByLink, $viewerEmail, $invite, $request));
    }

    /**
     * Only the agent's owner, signed in to Agent Host, can let teammates give it tasks.
     */
    public function updateParticipant(Request $request, string $token, ConcordParticipant $participant): JsonResponse
    {
        [$room] = $this->resolveRoomAccess($request, $token);
        if (! $room || $participant->concord_room_id !== $room->id) {
            return response()->json(['message' => 'Agent not found in this group.'], 404);
        }

        $sessionEmail = $request->session()->get('agent_host.authenticated')
            ? strtolower((string) data_get($request->session()->get('agent_host.user'), 'email', ''))
            : '';
        if ($sessionEmail === '' || $sessionEmail !== strtolower((string) $participant->account_email)) {
            return response()->json(['message' => 'Only the owner of this agent, signed in to Agent Host, can change this.'], 403);
        }

        $participant->update($request->validate(['accepts_team_tasks' => ['required', 'boolean']]));

        return response()->json(['participant' => $participant->fresh()]);
    }

    public function registerDevice(Request $request, string $token): JsonResponse
    {
        [$room, $invite, $permission, $projectRole, $bypassedByLink, $viewerEmail] = $this->resolveRoomAccess($request, $token);
        if (! $room) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        if ($permission !== 'edit') {
            return response()->json([
                'requires_email' => $permission === 'forbidden',
                'message' => 'Only admin or team_member (or projects with Anyone with link can edit enabled) can connect a local MCP agent to execute edits.',
            ], 403);
        }

        $validated = $request->validate([
            'participant_id' => ['nullable', 'string'],
            'user_name' => ['required', 'string', 'max:120'],
            'account_email' => ['nullable', 'string', 'max:190'],
            'device_name' => ['required', 'string', 'max:160'],
            'agent_name' => ['nullable', 'string', 'max:120'],
            'name' => ['nullable', 'string', 'max:120'],
            'provider' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:120'],
            'role_title' => ['nullable', 'string', 'max:160'],
        ]);

        $sessionUser = $request->session()->get('agent_host.user');
        $email = strtolower(trim($validated['account_email'] ?? $viewerEmail ?? ($sessionUser['email'] ?? '')));
        $participant = null;

        if (! empty($validated['participant_id'])) {
            $participant = $room->participants()->whereKey($validated['participant_id'])->first();
        }

        if (! $participant && $email !== '') {
            $participant = $room->participants()
                ->whereRaw('LOWER(account_email) = ?', [$email])
                ->where('device_name', $validated['device_name'])
                ->first();
        }

        $colors = ['#10b981', '#6366f1', '#f59e0b', '#ec4899', '#06b6d4', '#8b5cf6', '#f97316', '#14b8a6'];
        $provider = $validated['provider'] ?? 'claude';
        $agentName = $validated['agent_name'] ?? $validated['name'] ?? ($validated['user_name'].' '.ucfirst($provider).' MCP Agent');

        if ($participant) {
            $participant->update([
                'user_name' => $validated['user_name'],
                'account_email' => $email ?: $participant->account_email,
                'device_name' => $validated['device_name'],
                'execution_mode' => 'mcp_connected',
                'name' => $agentName,
                'provider' => $provider,
                'model' => $validated['model'] ?? $participant->model,
                'role_prompt' => $validated['role_title'] ?? $participant->role_prompt,
                'status' => 'idle',
                'current_thought' => 'MCP Handshake verified on '.$validated['device_name'].'. Ready for group chat prompts and peer handoffs.',
                'current_action' => 'MCP Connected ('.$validated['device_name'].')',
                'last_seen_at' => now(),
            ]);
        } else {
            $participant = $room->participants()->create([
                'kind' => 'agent',
                'name' => $agentName,
                'user_name' => $validated['user_name'],
                'account_email' => $email ?: (strtolower(preg_replace('/[^a-zA-Z0-9]+/', '.', $validated['user_name'])).'@local.mcp'),
                'device_name' => $validated['device_name'],
                'execution_mode' => 'mcp_connected',
                'provider' => $provider,
                'model' => $validated['model'] ?? 'claude-sonnet-5-5-high',
                'avatar' => 'MCP',
                'color' => $colors[$room->participants()->count() % count($colors)],
                'role_prompt' => $validated['role_title'] ?? 'Local MCP Workspace Agent',
                'status' => 'idle',
                'current_thought' => 'MCP Handshake verified from '.$validated['device_name'].'. Ready to coordinate on VPS shared memory and apply edits locally.',
                'current_action' => 'MCP Connected ('.$validated['device_name'].')',
                'thinking_steps' => [
                    'MCP Protocol 2024-11-05 handshake verified with VPS room '.$room->title,
                    'Linked local workspace on '.$validated['device_name'].' for user '.$validated['user_name'],
                    'Unlocked group chat prompting and cross-device file locks',
                ],
                'claimed_files' => [],
                'local_modified_files' => [],
                'last_seen_at' => now(),
            ]);

            $room->messages()->create([
                'participant_id' => $participant->id,
                'sender_name' => $participant->name,
                'sender_user' => $participant->user_name,
                'sender_device' => $participant->device_name,
                'sender_kind' => 'system',
                'sender_icon' => 'MCP',
                'sender_color' => '#10b981',
                'model' => $participant->model,
                'kind' => 'memory_update',
                'content' => "{$participant->user_name} connected MCP agent {$participant->name} on local computer {$participant->device_name} ({$participant->model}). Group Chat prompting is now unlocked for {$participant->user_name}, and all file edits for {$participant->user_name} execute directly on {$participant->device_name}.",
            ]);
        }

        $totalCount = $room->participants()->count();
        $room->update([
            'status' => 'active',
            'status_detail' => "{$totalCount} MCP agent(s) connected to VPS Shared Memory Hub - Ready for multi-agent prompting",
        ]);

        return response()->json([
            'participant' => $participant->fresh(),
            'mcp_handshake' => [
                'verified' => true,
                'protocolVersion' => '2024-11-05',
                'server' => 'concord-vps-shared-memory-hub',
                'tools' => ['get_shared_memory', 'write_shared_memory', 'send_group_message', 'report_thinking', 'pull_local_tasks'],
            ],
            'room' => $this->formatSharedRoom($room->fresh(), $permission, $projectRole, $bypassedByLink, $email ?: $viewerEmail, $invite, $request),
        ]);
    }

    public function destroyParticipant(Request $request, string $token, ConcordParticipant $participant): JsonResponse
    {
        [$room, $invite, $permission, $projectRole, $bypassedByLink, $viewerEmail] = $this->resolveRoomAccess($request, $token);
        if (! $room || $participant->concord_room_id !== $room->id) {
            return response()->json(['message' => 'Participant not found in this room.'], 404);
        }

        $sessionUser = $request->session()->get('agent_host.user');
        $isAdmin = is_array($sessionUser) && ($sessionUser['role'] ?? '') === 'admin';
        $lookupEmail = strtolower(trim((string) ($viewerEmail ?: ($sessionUser['email'] ?? ''))));
        $isOwn = $lookupEmail !== '' && strtolower((string) $participant->account_email) === $lookupEmail;

        if (! $isAdmin && ! $isOwn && $permission !== 'edit') {
            return response()->json(['message' => 'Not authorized to disconnect this MCP agent.'], 403);
        }

        $participant->delete();

        return response()->json([
            'ok' => true,
            'room' => $this->formatSharedRoom($room->fresh(), $permission, $projectRole, $bypassedByLink, $viewerEmail, $invite, $request),
        ]);
    }

    public function heartbeat(Request $request, string $token): JsonResponse
    {
        $room = $this->findRoomByToken($token);
        if (! $room) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        $validated = $request->validate([
            'participant_id' => ['nullable', 'string'],
            'device_name' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'string', 'max:40'],
            'current_thought' => ['nullable', 'string', 'max:2000'],
            'current_action' => ['nullable', 'string', 'max:500'],
            'thinking_steps' => ['nullable', 'array'],
            'local_modified_files' => ['nullable', 'array'],
        ]);

        $participant = null;
        if (! empty($validated['participant_id'])) {
            $participant = $room->participants()->whereKey($validated['participant_id'])->first();
        }
        if (! $participant && ! empty($validated['device_name'])) {
            $participant = $room->participants()->where('device_name', $validated['device_name'])->first();
        }
        if (! $participant) {
            $participant = $room->participants()->first();
        }

        if ($participant) {
            $updates = ['last_seen_at' => now()];
            foreach (['status', 'current_thought', 'current_action', 'thinking_steps', 'local_modified_files'] as $field) {
                if (array_key_exists($field, $validated) && $validated[$field] !== null) {
                    $updates[$field] = $validated[$field];
                }
            }
            $participant->update($updates);
        }

        return response()->json([
            'ok' => true,
            'participant' => $participant?->fresh(),
            'shared_memory' => $room->shared_memory ?? [],
            'active_claims' => $room->active_claims ?? [],
        ]);
    }

    public function pullTask(Request $request, string $token): JsonResponse
    {
        $room = $this->findRoomByToken($token);
        if (! $room) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        $participantId = $request->query('participant_id');
        $deviceName = $request->query('device_name');

        // Only prompts the agent's owner sent are runnable; plain room messages are read via MCP.
        $query = $room->localTasks()->where('status', 'queued')->where('kind', 'prompt')->oldest('created_at');
        if ($participantId) {
            $query->where('target_participant_id', $participantId);
        } elseif ($deviceName) {
            $query->where('target_device', $deviceName);
        }

        $task = $query->first();
        if ($task) {
            $task->update([
                'status' => 'running',
                'delivered_at' => now(),
            ]);

            if ($task->participant) {
                $task->participant->update([
                    'status' => 'editing_local',
                    'current_thought' => 'Executing local file edits on '.$task->target_device.' from VPS shared memory...',
                    'current_action' => 'Applying local workspace edits on '.$task->target_device,
                    'last_seen_at' => now(),
                ]);
            }
        }

        return response()->json([
            'task' => $task?->fresh(),
            'shared_memory' => $room->shared_memory ?? [],
            'active_claims' => $room->active_claims ?? [],
        ]);
    }

    public function completeTask(Request $request, string $token, ConcordLocalTask $task): JsonResponse
    {
        $room = $this->findRoomByToken($token);
        if (! $room || $task->concord_room_id !== $room->id) {
            return response()->json(['message' => 'Task not found in this room.'], 404);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:completed,failed'],
            'modified_files' => ['nullable'],
            'result_summary' => ['nullable', 'string', 'max:4000'],
            'summary' => ['nullable', 'string', 'max:4000'],
        ]);

        $rawFiles = $validated['modified_files'] ?? $task->modified_files ?? [];
        if (is_string($rawFiles)) {
            $modifiedFiles = array_values(array_filter(array_map('trim', explode(',', $rawFiles))));
        } else {
            $modifiedFiles = is_array($rawFiles) ? $rawFiles : [];
        }

        $summary = $validated['result_summary'] ?? $validated['summary'] ?? ('Applied local workspace changes on '.$task->target_device.'.');

        $task->update([
            'status' => $validated['status'] ?? 'completed',
            'modified_files' => $modifiedFiles,
            'result_summary' => $summary,
            'reply' => $summary,
            'completed_at' => now(),
        ]);

        $participant = $task->participant;
        if ($participant) {
            $participant->update([
                'status' => 'idle',
                'current_thought' => 'Completed local file edits on '.$participant->device_name.' and synced status to VPS shared memory.',
                'current_action' => 'Local edits applied on '.$participant->device_name,
                'local_modified_files' => $modifiedFiles,
                'last_seen_at' => now(),
            ]);
        }

        $room->messages()->create([
            'participant_id' => $participant?->id,
            'sender_name' => $participant?->name ?? ($task->target_agent_name ?: 'Local Agent'),
            'sender_user' => $participant?->user_name ?? 'Local User',
            'sender_device' => $task->target_device,
            'sender_kind' => $participant?->provider ?? 'claude',
            'sender_icon' => 'EDIT',
            'sender_color' => '#10b981',
            'model' => $participant?->model ?? 'local-agent',
            'kind' => 'local_edit',
            'thinking_text' => "Pulled task #{$task->id} from VPS Shared Memory Hub\nModified ".count($modifiedFiles)." file(s) directly on {$task->target_device}\nSynced completion receipt back to group chat",
            'tool_calls' => [
                [
                    'tool' => 'LocalWorkspaceApply',
                    'target' => $task->target_device.' -> '.implode(', ', $modifiedFiles),
                    'output' => $summary,
                ],
            ],
            'local_changes' => array_map(fn ($f) => [
                'file' => $f,
                'action' => 'applied_on_local_device',
                'device' => $task->target_device,
                'user' => $participant?->user_name ?? $task->target_agent_name,
            ], $modifiedFiles),
            'content' => "[LOCAL EDITS APPLIED ON {$task->target_device}]: {$summary}",
        ]);

        return response()->json([
            'task' => $task->fresh(),
        ]);
    }

    public function connectorScript(Request $request, string $token, ConcordHubService $hub): Response
    {
        $room = $this->findRoomByToken($token);
        if (! $room) {
            return response("# Error: Concord Room not found.\n", 404, ['Content-Type' => 'text/plain']);
        }

        $participantId = $request->query('participant_id') ? (string) $request->query('participant_id') : null;
        $userEmail = $request->query('email') ? (string) $request->query('email') : null;
        $userName = $request->query('user') ? (string) $request->query('user') : null;
        // ?channel=yes|no pre-answers the "start idle sessions instantly" question; anything else asks.
        $channel = in_array($request->query('channel'), ['yes', 'no'], true) ? $request->query('channel') : '';
        $script = $hub->generateConnectorScript($room, $request->getSchemeAndHttpHost(), $participantId, $userEmail, $userName, $channel);

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    public function mcp(Request $request, string $mcpToken, ConcordMcpServer $server): JsonResponse|Response
    {
        $room = ConcordRoom::query()->where('mcp_token', $mcpToken)->first();
        if (! $room) {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $request->json('id'),
                'error' => ['code' => -32001, 'message' => 'Invalid Concord MCP token'],
            ], 404);
        }

        return $server->handle($request, $room);
    }

    public function mcpEvent(Request $request, string $mcpToken, ConcordMcpServer $server): JsonResponse
    {
        $room = ConcordRoom::query()->where('mcp_token', $mcpToken)->first();
        abort_unless($room, 404);

        return $server->handleEvent($request, $room);
    }

    public function mcpInbox(Request $request, string $mcpToken, ConcordMcpServer $server): JsonResponse
    {
        $room = ConcordRoom::query()->where('mcp_token', $mcpToken)->first();
        abort_unless($room, 404);

        return $server->handleInbox($request, $room);
    }

    /**
     * Streamable HTTP clients may open a GET event stream or DELETE their session;
     * this server is stateless and answers over POST only, which the spec signals with 405.
     */
    public function mcpMethodNotAllowed(): Response
    {
        return response('', 405, ['Allow' => 'POST']);
    }

    /**
     * @return array{0: ?ConcordRoom, 1: ?ConcordRoomInvite, 2: string, 3: string, 4: bool, 5: ?string}
     */
    protected function resolveRoomAccess(Request $request, string $token): array
    {
        $invite = ConcordRoomInvite::query()->where('invite_token', $token)->first();
        $room = $invite ? $invite->room : ConcordRoom::query()->where('share_token', $token)->first();

        if (! $room) {
            return [null, null, 'forbidden', 'no_role', false, null];
        }

        $isAuthenticated = (bool) $request->session()->get('agent_host.authenticated', false);
        $sessionUser = $request->session()->get('agent_host.user');
        $globalRole = $isAuthenticated
            ? (is_array($sessionUser) ? ($sessionUser['role'] ?? 'admin') : 'admin')
            : 'no_role';

        $viewerEmail = strtolower(trim((string) (
            $request->query('email')
            ?? $request->input('sender_email')
            ?? $request->header('X-Invite-Email')
            ?? ($sessionUser['email'] ?? '')
        )));

        // 1. Admin always has full edit & project management access
        if ($globalRole === 'admin') {
            return [$room, $invite, 'edit', 'admin', false, $viewerEmail ?: $room->owner_email];
        }

        // 2. If room is set to "Anyone with the link can edit" (link_edit), role check is bypassed!
        if ($room->access_mode === 'link_edit') {
            return [$room, $invite, 'edit', 'team_member', true, $viewerEmail ?: ($invite?->email)];
        }

        // 3. Otherwise (invite_only or link_view), check per-project role in invites
        if ($invite) {
            $isTeamMember = in_array($invite->role, ['team_member', 'editor', 'admin'], true);

            return [
                $room,
                $invite,
                $isTeamMember ? 'edit' : 'view',
                $isTeamMember ? 'team_member' : 'no_role',
                false,
                $invite->email,
            ];
        }

        if ($viewerEmail !== '') {
            if (strtolower((string) $room->owner_email) === $viewerEmail) {
                return [$room, null, 'edit', 'admin', false, $viewerEmail];
            }
            $matchedInvite = $room->invites()
                ->whereRaw('LOWER(email) = ?', [$viewerEmail])
                ->first();
            if ($matchedInvite) {
                $isTeamMember = in_array($matchedInvite->role, ['team_member', 'editor', 'admin'], true);

                return [
                    $room,
                    $matchedInvite,
                    $isTeamMember ? 'edit' : 'view',
                    $isTeamMember ? 'team_member' : 'no_role',
                    false,
                    $viewerEmail,
                ];
            }
        }

        if ($room->access_mode === 'link_view') {
            return [$room, null, 'view', 'no_role', false, $viewerEmail ?: null];
        }

        return [$room, null, 'forbidden', 'no_role', false, $viewerEmail ?: null];
    }

    protected function findRoomByToken(string $token): ?ConcordRoom
    {
        $room = ConcordRoom::query()->where('share_token', $token)->first();
        if ($room) {
            return $room;
        }

        return ConcordRoomInvite::query()->where('invite_token', $token)->first()?->room;
    }

    protected function formatSharedRoom(
        ConcordRoom $room,
        string $permission,
        string $projectRole,
        bool $bypassedByLink,
        ?string $viewerEmail,
        ?ConcordRoomInvite $invite,
        Request $request
    ): array {
        $isAuthenticated = (bool) $request->session()->get('agent_host.authenticated', false);
        $sessionUser = $request->session()->get('agent_host.user');
        $globalRole = $isAuthenticated
            ? (is_array($sessionUser) ? ($sessionUser['role'] ?? 'admin') : 'admin')
            : 'no_role';

        $room->load([
            'participants' => fn ($q) => $q->orderBy('created_at'),
            'invites' => fn ($q) => $q->orderBy('created_at'),
            'messages' => fn ($q) => $q->latest('id')->limit(120),
            'localTasks' => fn ($q) => $q->latest('created_at')->limit(40),
        ]);

        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');
        $platformUsers = $globalRole === 'admin'
            ? User::query()->orderBy('id')->get(['id', 'name', 'username', 'email', 'phone', 'role'])->toArray()
            : [];

        return [
            'id' => $room->id,
            'title' => $room->title,
            'description' => $room->description,
            'owner_email' => $room->owner_email,
            'access_mode' => $room->access_mode,
            'permission' => $permission,
            'project_role' => $projectRole,
            'global_role' => $globalRole,
            'can_manage_room' => $globalRole === 'admin',
            'role_bypassed_by_link' => $bypassedByLink,
            'roles_catalog' => ConcordHubService::rolesCatalog(),
            'platform_users' => $platformUsers,
            'viewer_email' => $viewerEmail,
            'invite' => $invite,
            'share_token' => $room->share_token,
            'mcp_token' => $room->mcp_token,
            'share_url' => $baseUrl.'/?room='.$room->share_token,
            'mcp_endpoint' => $baseUrl.'/api/concord/mcp/'.$room->mcp_token,
            'connect_command' => 'curl -fsSL '.$baseUrl.'/connect/'.$room->share_token.' | bash',
            'shared_memory' => $room->shared_memory ?? [],
            'active_claims' => $room->active_claims ?? [],
            'status' => $room->status,
            'status_detail' => $room->status_detail,
            'participants' => $room->participants->values(),
            'invites' => $room->invites->values(),
            'messages' => $room->messages->reverse()->values(),
            'local_tasks' => $room->localTasks->values(),
            'updated_at' => $room->updated_at,
        ];
    }
}
