<?php

namespace App\Http\Controllers;

use App\Models\ConcordLocalTask;
use App\Models\ConcordMessage;
use App\Models\ConcordParticipant;
use App\Models\ConcordRoom;
use App\Models\ConcordRoomInvite;
use App\Services\ConcordHubService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class ConcordSharedController extends Controller
{
    public function show(Request $request, string $token, ConcordHubService $hub): JsonResponse
    {
        [$room, $invite, $permission, $viewerEmail] = $this->resolveRoomAccess($request, $token);
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
                'message' => 'This group chat room is invite-only. Enter your invited email address to join.',
            ], 403);
        }

        if ($invite && ! $invite->accepted_at) {
            $invite->update(['accepted_at' => now()]);
        }

        return response()->json($this->formatSharedRoom($room, $permission, $viewerEmail, $invite, $request->getSchemeAndHttpHost()));
    }

    public function storeMessage(Request $request, string $token, ConcordHubService $hub): JsonResponse
    {
        [$room, $invite, $permission, $viewerEmail] = $this->resolveRoomAccess($request, $token);
        if (! $room) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        if ($permission !== 'edit') {
            return response()->json([
                'message' => 'You have view-only access to this room. Ask the room owner for editor permission to send prompts or queue local edits.',
            ], 403);
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:12000'],
            'sender_name' => ['nullable', 'string', 'max:120'],
            'sender_email' => ['nullable', 'string', 'max:190'],
            'sender_device' => ['nullable', 'string', 'max:160'],
            'target_agent' => ['nullable', 'string', 'max:120'],
            'participant_id' => ['nullable', 'integer'],
        ]);

        if (empty($validated['sender_email']) && $viewerEmail) {
            $validated['sender_email'] = $viewerEmail;
        }

        if (! empty($validated['participant_id'])) {
            $participant = $room->participants()->whereKey($validated['participant_id'])->first();
            if ($participant) {
                $validated['sender_name'] = $validated['sender_name'] ?? $participant->user_name;
                $validated['sender_email'] = $validated['sender_email'] ?? $participant->account_email;
                $validated['sender_device'] = $validated['sender_device'] ?? $participant->device_name;
            }
        }

        $hub->sendPrompt($room, $validated);

        return response()->json($this->formatSharedRoom($room->fresh(), $permission, $viewerEmail, $invite, $request->getSchemeAndHttpHost()));
    }

    public function registerDevice(Request $request, string $token): JsonResponse
    {
        [$room, $invite, $permission, $viewerEmail] = $this->resolveRoomAccess($request, $token);
        if (! $room) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        if ($permission === 'forbidden') {
            return response()->json([
                'requires_email' => true,
                'message' => 'Invite-only room: provide an invited email address to register your local computer.',
            ], 403);
        }

        $validated = $request->validate([
            'participant_id' => ['nullable', 'integer'],
            'user_name' => ['required', 'string', 'max:120'],
            'account_email' => ['nullable', 'string', 'max:190'],
            'device_name' => ['required', 'string', 'max:160'],
            'agent_name' => ['nullable', 'string', 'max:120'],
            'provider' => ['nullable', 'string', 'max:60'],
            'model' => ['nullable', 'string', 'max:120'],
            'role_title' => ['nullable', 'string', 'max:160'],
        ]);

        $email = strtolower(trim($validated['account_email'] ?? $viewerEmail ?? ''));
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

        $colors = ['#10b981', '#6366f1', '#f59e0b', '#ec4899', '#06b6d4', '#8b5cf6'];
        $provider = $validated['provider'] ?? 'claude';
        $agentName = $validated['agent_name'] ?? ($validated['user_name'].'\'s '.ucfirst($provider).' Agent');

        if ($participant) {
            $participant->update([
                'user_name' => $validated['user_name'],
                'account_email' => $email ?: $participant->account_email,
                'device_name' => $validated['device_name'],
                'agent_name' => $agentName,
                'provider' => $provider,
                'model' => $validated['model'] ?? $participant->model,
                'role_title' => $validated['role_title'] ?? $participant->role_title,
                'connection_mode' => 'local_connector',
                'status' => 'idle',
                'current_action' => 'Connected from local computer ('.$validated['device_name'].')',
                'last_seen_at' => now(),
            ]);
        } else {
            $participant = $room->participants()->create([
                'user_name' => $validated['user_name'],
                'account_email' => $email ?: ($validated['user_name'].'@local.dev'),
                'device_name' => $validated['device_name'],
                'agent_name' => $agentName,
                'provider' => $provider,
                'model' => $validated['model'] ?? 'claude-opus-4-6',
                'role_title' => $validated['role_title'] ?? 'Local Workspace Agent',
                'avatar_color' => $colors[$room->participants()->count() % count($colors)],
                'connection_mode' => 'local_connector',
                'permission' => $permission === 'edit' ? 'editor' : 'viewer',
                'status' => 'idle',
                'current_thought' => 'Connected to VPS shared memory bank from '.$validated['device_name'].'. Waiting for group chat prompts or peer handoffs.',
                'current_action' => 'Connected from local computer ('.$validated['device_name'].')',
                'thinking_steps' => [
                    'Linked local workspace on '.$validated['device_name'].' to VPS room '.$room->slug,
                    'Synced shared memory bank and active cross-device file claims',
                ],
                'local_modified_files' => [],
                'device_token' => 'dev_'.Str::random(24),
                'last_seen_at' => now(),
            ]);

            $room->messages()->create([
                'participant_id' => $participant->id,
                'sender_type' => 'system',
                'sender_name' => $participant->agent_name,
                'sender_email' => $participant->account_email,
                'sender_device' => $participant->device_name,
                'provider' => $participant->provider,
                'model' => $participant->model,
                'kind' => 'memory_sync',
                'body' => "**{$participant->user_name}** connected local computer `{$participant->device_name}` running **{$participant->agent_name}** (`{$participant->model}`). All prompts and file edits for {$participant->user_name} will execute directly on `{$participant->device_name}` while syncing context memory on this VPS.",
            ]);
        }

        return response()->json([
            'participant' => $participant->fresh(),
            'room' => $this->formatSharedRoom($room->fresh(), $permission, $email ?: $viewerEmail, $invite, $request->getSchemeAndHttpHost()),
        ]);
    }

    public function heartbeat(Request $request, string $token): JsonResponse
    {
        $room = $this->findRoomByToken($token);
        if (! $room) {
            return response()->json(['message' => 'Room not found.'], 404);
        }

        $validated = $request->validate([
            'participant_id' => ['nullable', 'integer'],
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

        $query = $room->localTasks()->where('status', 'queued')->oldest('id');
        if ($participantId) {
            $query->where('target_participant_id', (int) $participantId);
        } elseif ($deviceName) {
            $query->where('target_device', $deviceName);
        }

        $task = $query->first();
        if ($task) {
            $task->update([
                'status' => 'executing_on_device',
            ]);

            if ($task->targetParticipant) {
                $task->targetParticipant->update([
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
        if (! $room || $task->room_id !== $room->id) {
            return response()->json(['message' => 'Task not found in this room.'], 404);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:completed,failed'],
            'modified_files' => ['nullable', 'array'],
            'result_summary' => ['nullable', 'string', 'max:4000'],
        ]);

        $modifiedFiles = $validated['modified_files'] ?? $task->modified_files ?? [];
        $summary = $validated['result_summary'] ?? ('Applied local workspace changes on '.$task->target_device.'.');

        $task->update([
            'status' => $validated['status'] ?? 'completed',
            'modified_files' => $modifiedFiles,
            'result_summary' => $summary,
            'executed_at' => now(),
        ]);

        $participant = $task->targetParticipant;
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
            'sender_type' => 'agent',
            'sender_name' => $participant?->agent_name ?? ($task->target_user_name.' Local Agent'),
            'sender_email' => $participant?->account_email,
            'sender_device' => $task->target_device,
            'provider' => $participant?->provider ?? 'claude',
            'model' => $participant?->model ?? 'local-agent',
            'kind' => 'local_edit',
            'thinking_summary' => 'Executed queued task on local machine '.$task->target_device,
            'thinking_steps' => [
                'Pulled task #'.$task->id.' from VPS Shared Memory Hub',
                'Modified '.count($modifiedFiles).' file(s) directly on '.$task->target_device,
                'Synced completion receipt back to group chat',
            ],
            'tool_calls' => [
                [
                    'tool' => 'LocalWorkspaceApply',
                    'summary' => $task->target_device.' → '.implode(', ', $modifiedFiles),
                    'output' => $summary,
                ],
            ],
            'local_changes' => [
                'executed_on_device' => $task->target_device,
                'owner' => $task->target_user_name,
                'files' => $modifiedFiles,
            ],
            'body' => "✅ **Local Computer Edits Applied on `{$task->target_device}`** ({$task->target_user_name}):\n\n{$summary}",
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

        $participantId = $request->query('participant_id') ? (int) $request->query('participant_id') : null;
        $script = $hub->generateConnectorScript($room, $request->getSchemeAndHttpHost(), $participantId);

        return response($script, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }

    public function mcp(Request $request, string $mcpToken, ConcordHubService $hub): JsonResponse
    {
        $room = ConcordRoom::query()->where('mcp_token', $mcpToken)->first();
        if (! $room) {
            return response()->json([
                'jsonrpc' => '2.0',
                'error' => ['code' => -32001, 'message' => 'Invalid Concord MCP token'],
                'id' => $request->input('id'),
            ], 404);
        }

        $method = $request->input('method', 'tools/list');
        $id = $request->input('id', 1);
        $params = $request->input('params', []);

        if ($method === 'initialize') {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'protocolVersion' => '2024-11-05',
                    'serverInfo' => [
                        'name' => 'concord-vps-shared-memory-hub',
                        'version' => '2.0.0',
                        'room' => $room->title,
                    ],
                    'capabilities' => [
                        'tools' => (object) [],
                    ],
                ],
            ]);
        }

        if ($method === 'tools/list') {
            return response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'result' => [
                    'tools' => [
                        [
                            'name' => 'get_shared_memory',
                            'description' => 'Read the centralized VPS shared memory bank, active cross-device file claims, recent group chat messages, and pending local computer tasks.',
                            'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                        ],
                        [
                            'name' => 'write_shared_memory',
                            'description' => 'Write an architectural decision, API contract, or shared context key to the VPS shared memory bank so all connected agents see it immediately.',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'section' => ['type' => 'string', 'enum' => ['architecture', 'contracts', 'decisions']],
                                    'key' => ['type' => 'string'],
                                    'value' => ['type' => 'string'],
                                    'author' => ['type' => 'string'],
                                ],
                                'required' => ['section', 'value'],
                            ],
                        ],
                        [
                            'name' => 'send_group_message',
                            'description' => 'Post a message to the multi-agent group chat on the VPS and optionally hand off local workspace edits to another user computer.',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'sender_name' => ['type' => 'string'],
                                    'sender_device' => ['type' => 'string'],
                                    'target_agent' => ['type' => 'string'],
                                    'body' => ['type' => 'string'],
                                ],
                                'required' => ['body'],
                            ],
                        ],
                        [
                            'name' => 'report_thinking',
                            'description' => 'Stream live agent thinking steps and local file edits from your local computer to the VPS group chat visualizer.',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'participant_id' => ['type' => 'integer'],
                                    'status' => ['type' => 'string'],
                                    'current_thought' => ['type' => 'string'],
                                    'current_action' => ['type' => 'string'],
                                    'thinking_steps' => ['type' => 'array', 'items' => ['type' => 'string']],
                                    'local_modified_files' => ['type' => 'array', 'items' => ['type' => 'string']],
                                ],
                            ],
                        ],
                        [
                            'name' => 'pull_local_tasks',
                            'description' => 'Pull queued file-edit instructions targeting your local computer from the VPS hub.',
                            'inputSchema' => [
                                'type' => 'object',
                                'properties' => [
                                    'participant_id' => ['type' => 'integer'],
                                    'device_name' => ['type' => 'string'],
                                ],
                            ],
                        ],
                    ],
                ],
            ]);
        }

        if ($method === 'tools/call') {
            $toolName = $params['name'] ?? '';
            $args = $params['arguments'] ?? [];

            if ($toolName === 'get_shared_memory') {
                return response()->json([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'content' => [[
                            'type' => 'text',
                            'text' => json_encode([
                                'room' => $room->title,
                                'shared_memory' => $room->shared_memory,
                                'active_claims' => $room->active_claims,
                                'participants' => $room->participants()->get(['id', 'user_name', 'device_name', 'agent_name', 'model', 'status', 'current_thought']),
                                'recent_messages' => $room->messages()->latest('id')->limit(10)->get(['sender_name', 'sender_device', 'kind', 'body']),
                            ], JSON_PRETTY_PRINT),
                        ]],
                    ],
                ]);
            }

            if ($toolName === 'write_shared_memory') {
                $memory = $room->shared_memory ?? ['architecture' => [], 'contracts' => [], 'decisions' => []];
                $section = $args['section'] ?? 'decisions';
                if ($section === 'decisions') {
                    $memory['decisions'][] = [
                        'author' => $args['author'] ?? 'Remote MCP Agent',
                        'summary' => $args['value'] ?? '',
                        'timestamp' => now()->toIso8601String(),
                    ];
                } else {
                    $memory[$section][$args['key'] ?? 'item'] = $args['value'] ?? '';
                }
                $room->update(['shared_memory' => $memory]);

                return response()->json([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'content' => [['type' => 'text', 'text' => 'Updated VPS shared memory section: '.$section]],
                    ],
                ]);
            }

            if ($toolName === 'send_group_message') {
                $msg = $hub->sendPrompt($room, [
                    'sender_name' => $args['sender_name'] ?? 'Remote MCP Agent',
                    'sender_device' => $args['sender_device'] ?? 'Local Computer via MCP',
                    'target_agent' => $args['target_agent'] ?? 'all',
                    'body' => $args['body'] ?? '',
                ]);

                return response()->json([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'content' => [['type' => 'text', 'text' => 'Posted group chat message #'.$msg->id]],
                    ],
                ]);
            }

            if ($toolName === 'report_thinking') {
                $participant = ! empty($args['participant_id'])
                    ? $room->participants()->whereKey($args['participant_id'])->first()
                    : $room->participants()->first();

                if ($participant) {
                    $participant->update(array_filter([
                        'status' => $args['status'] ?? 'thinking',
                        'current_thought' => $args['current_thought'] ?? null,
                        'current_action' => $args['current_action'] ?? null,
                        'thinking_steps' => $args['thinking_steps'] ?? null,
                        'local_modified_files' => $args['local_modified_files'] ?? null,
                        'last_seen_at' => now(),
                    ], fn ($v) => $v !== null));
                }

                return response()->json([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'content' => [['type' => 'text', 'text' => 'Live thinking telemetry synced to VPS visualizer.']],
                    ],
                ]);
            }

            if ($toolName === 'pull_local_tasks') {
                $task = $room->localTasks()
                    ->where('status', 'queued')
                    ->when(! empty($args['participant_id']), fn ($q) => $q->where('target_participant_id', $args['participant_id']))
                    ->when(! empty($args['device_name']), fn ($q) => $q->where('target_device', $args['device_name']))
                    ->oldest('id')
                    ->first();

                return response()->json([
                    'jsonrpc' => '2.0',
                    'id' => $id,
                    'result' => [
                        'content' => [['type' => 'text', 'text' => json_encode($task)]],
                    ],
                ]);
            }
        }

        return response()->json([
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => -32601, 'message' => 'Unsupported MCP method: '.$method],
        ], 400);
    }

    /**
     * @return array{0: ?ConcordRoom, 1: ?ConcordRoomInvite, 2: string, 3: ?string}
     */
    protected function resolveRoomAccess(Request $request, string $token): array
    {
        $invite = ConcordRoomInvite::query()->where('invite_token', $token)->first();
        $room = $invite ? $invite->room : ConcordRoom::query()->where('share_token', $token)->first();

        if (! $room) {
            return [null, null, 'forbidden', null];
        }

        $isAdmin = (bool) $request->session()->get('agent_host.authenticated', false);
        $viewerEmail = strtolower(trim((string) ($request->query('email') ?? $request->input('sender_email') ?? $request->header('X-Invite-Email') ?? '')));

        if ($invite) {
            return [$room, $invite, $invite->role === 'editor' ? 'edit' : 'view', $invite->email];
        }

        if ($isAdmin) {
            return [$room, null, 'edit', $viewerEmail ?: $room->owner_email];
        }

        if ($viewerEmail !== '') {
            if (strtolower((string) $room->owner_email) === $viewerEmail) {
                return [$room, null, 'edit', $viewerEmail];
            }
            $matchedInvite = $room->invites()
                ->whereRaw('LOWER(email) = ?', [$viewerEmail])
                ->first();
            if ($matchedInvite) {
                return [$room, $matchedInvite, $matchedInvite->role === 'editor' ? 'edit' : 'view', $viewerEmail];
            }
        }

        if ($room->access_mode === 'link_edit') {
            return [$room, null, 'edit', $viewerEmail ?: null];
        }

        if ($room->access_mode === 'link_view') {
            return [$room, null, 'view', $viewerEmail ?: null];
        }

        return [$room, null, 'forbidden', $viewerEmail ?: null];
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
        ?string $viewerEmail,
        ?ConcordRoomInvite $invite,
        string $baseUrl
    ): array {
        $room->load([
            'participants',
            'invites',
            'messages' => fn ($query) => $query->latest('id')->limit(120),
            'localTasks' => fn ($query) => $query->latest('id')->limit(40),
        ]);

        return [
            'id' => $room->id,
            'name' => $room->name,
            'slug' => $room->slug,
            'title' => $room->title,
            'description' => $room->description,
            'owner_name' => $room->owner_name,
            'owner_email' => $room->owner_email,
            'access_mode' => $room->access_mode,
            'permission' => $permission,
            'viewer_email' => $viewerEmail,
            'invite' => $invite,
            'share_token' => $room->share_token,
            'mcp_token' => $room->mcp_token,
            'share_url' => rtrim($baseUrl, '/').'/?room='.$room->share_token,
            'mcp_endpoint' => rtrim($baseUrl, '/').'/api/concord/mcp/'.$room->mcp_token,
            'connect_command' => 'curl -fsSL '.rtrim($baseUrl, '/').'/connect/'.$room->share_token.' | bash',
            'shared_memory' => $room->shared_memory ?? [],
            'active_claims' => $room->active_claims ?? [],
            'status' => $room->status,
            'participants' => $room->participants->values(),
            'invites' => $room->invites->values(),
            'messages' => $room->messages->reverse()->values(),
            'local_tasks' => $room->localTasks->values(),
            'updated_at' => $room->updated_at,
        ];
    }
}
