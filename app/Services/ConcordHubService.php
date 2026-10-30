<?php

namespace App\Services;

use App\Jobs\RunConcordAgentTalk;
use App\Models\ConcordParticipant;
use App\Models\ConcordRoom;
use Illuminate\Support\Str;

class ConcordHubService
{
    public function __construct(private LlmCascade $cascade) {}

    public static function rolesCatalog(): array
    {
        return [
            [
                'id' => 'admin',
                'label' => 'admin',
                'title' => 'Admin (Platform & Project Owner)',
                'can_view_vps_fleet' => true,
                'can_create_room' => true,
                'can_manage_roles' => true,
                'can_edit_group_chat' => true,
                'can_view_group_chat' => true,
                'description' => 'Full platform and project privileges. Only Admin can view and operate the live VPS Claude / Antigravity Fleet sessions, create or delete Group Chat projects, change project link access modes, and assign per-project roles (team_member or no_role) to invited users. Admin can also view and edit all Group Chat projects.',
                'permissions' => [
                    'Access and control live VPS Claude / Antigravity Fleet sessions & LLM Council',
                    'Create and delete Group Chat projects/sessions',
                    'Invite users by email and set each member role to team_member or no_role',
                    'Configure link sharing mode (Restricted, Anyone can view, or Anyone can edit)',
                    'Full Group Chat edit & view access (send prompts, connect local PC/MCP, and edit shared memory)',
                ],
            ],
            [
                'id' => 'team_member',
                'label' => 'team_member',
                'title' => 'Team Member (Project Editor & Collaborator)',
                'can_view_vps_fleet' => false,
                'can_create_room' => false,
                'can_manage_roles' => false,
                'can_edit_group_chat' => true,
                'can_view_group_chat' => true,
                'description' => 'Active project collaborator. Can both view and edit inside the Group Chat project they are assigned to: send prompts, coordinate multi-agent handoffs, connect their own local computer / CLI agent so file changes execute on their local machine, and pin updates to the VPS Shared Memory Bank. Cannot view the Admin live VPS Fleet sessions or create new Group Chat projects.',
                'permissions' => [
                    'View live Group Chat messages, agent thinking traces, and VPS Shared Memory',
                    'Send prompts and trigger cross-computer multi-agent handoffs in the Group Chat',
                    'Connect their local computer / CLI agent via 1-button registration, 1-line script, or Remote MCP',
                    'Write and pin architectural contracts to the project VPS Shared Memory Bank',
                    'Cannot access Admin private live VPS Claude Fleet sessions or create new projects',
                ],
            ],
            [
                'id' => 'no_role',
                'label' => 'no_role',
                'title' => 'No Role (Default — Read-Only Group Chat Viewer)',
                'can_view_vps_fleet' => false,
                'can_create_room' => false,
                'can_manage_roles' => false,
                'can_edit_group_chat' => false,
                'can_view_group_chat' => true,
                'description' => 'Default role for all newly registered users and unpromoted members. Users with no_role cannot access live VPS Fleet sessions and can only view the Group Chat in read-only mode (watching live agent conversations, thinking steps, and shared memory) unless the Admin sets the project link mode to Anyone with link can edit (which bypasses per-project role checks) or upgrades their project role to team_member.',
                'permissions' => [
                    'Assigned by default to every new user account upon sign-up',
                    'View-only access to the Group Chat (watch live agent talks, thinking steps, and shared memory)',
                    'Cannot send prompts, connect local computers for edits, or modify shared memory (unless bypassed by Anyone with link can edit)',
                    'Cannot access Admin private live VPS Claude Fleet sessions or create new projects',
                ],
            ],
        ];
    }

    public function ensureDefaultRoom(): ConcordRoom
    {
        $existing = ConcordRoom::oldest('created_at')->first();

        if ($existing) {
            return $existing;
        }

        $room = ConcordRoom::create([
            'title' => 'Concord Multi-Agent Shared Workspace',
            'description' => 'Central VPS Context Memory & Multi-Agent Group Chat. Prompts & file edits execute on each user\'s own computer while all context memory and agent-to-agent talks live here.',
            'owner_email' => 'bryankunhahaha@gmail.com',
            'access_mode' => 'invite_only',
            'status' => 'active',
            'status_detail' => 'Shared Memory synchronized - Ready for cross-device agent collaboration',
            'shared_memory' => [
                [
                    'id' => 'mem_arch_1',
                    'key' => 'execution_topology',
                    'category' => 'architecture',
                    'content' => 'VPS holds shared context memory, session state, and agent-to-agent group chat. File edits execute on each user\'s local computer.',
                    'author_agent' => 'Concord Hub',
                    'author_user' => 'System',
                    'updated_at' => now()->toIso8601String(),
                ],
            ],
            'active_claims' => [],
        ]);

        $room->participants()->createMany([
            [
                'kind' => 'agent',
                'name' => 'Claude Code - User 1',
                'user_name' => 'User 1 (Owner)',
                'account_email' => 'bryankunhahaha@gmail.com',
                'device_name' => 'User-1-Workstation',
                'execution_mode' => 'local_device',
                'provider' => 'claude',
                'model' => 'claude-sonnet-5-5-high',
                'avatar' => 'A1',
                'color' => '#ff5722',
                'role_prompt' => 'Primary architecture & backend/frontend agent for User 1. Edits files on User 1\'s local computer and coordinates API contracts with User 2\'s agent.',
                'status' => 'idle',
                'current_thought' => 'Connected to VPS Shared Memory - waiting for User 1 prompt or peer message',
                'current_action' => 'Idle on User-1-Workstation',
                'thinking_steps' => ['Synchronized with VPS shared context window', 'Ready to edit files locally on User-1-Workstation'],
                'claimed_files' => [],
                'local_modified_files' => [],
                'last_seen_at' => now(),
            ],
            [
                'kind' => 'agent',
                'name' => 'Antigravity Gemini - User 2',
                'user_name' => 'User 2 (Collaborator)',
                'account_email' => 'collaborator@team.dev',
                'device_name' => 'User-2-Laptop',
                'execution_mode' => 'local_device',
                'provider' => 'antigravity',
                'model' => 'gemini-3.8-flash-high',
                'avatar' => 'A2',
                'color' => '#00d2ff',
                'role_prompt' => 'Partner agent for User 2. Communicates with User 1\'s agent over the VPS shared context window and applies corresponding code changes directly on User 2\'s computer.',
                'status' => 'idle',
                'current_thought' => 'Listening to Concord group chat for handoffs from User 1\'s agent',
                'current_action' => 'Idle on User-2-Laptop',
                'thinking_steps' => ['Listening on Concord MCP channel', 'Ready to apply synced changes on User-2-Laptop'],
                'claimed_files' => [],
                'local_modified_files' => [],
                'last_seen_at' => now(),
            ],
        ]);

        return $room;
    }

    public function createRoom(array $data): ConcordRoom
    {
        $room = ConcordRoom::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? 'Cross-account multi-agent group chat with centralized VPS context memory and local device execution.',
            'owner_email' => $data['owner_email'] ?? 'bryankunhahaha@gmail.com',
            'access_mode' => $data['access_mode'] ?? 'invite_only',
            'status' => 'active',
            'status_detail' => 'Room initialized - connect local agents via 1-click link or MCP',
            'shared_memory' => [
                [
                    'id' => 'mem_init',
                    'key' => 'workspace_goal',
                    'category' => 'decision',
                    'content' => $data['description'] ?? "Collaborative workspace for {$data['title']}",
                    'author_agent' => 'Concord Hub',
                    'author_user' => $data['owner_email'] ?? 'Owner',
                    'updated_at' => now()->toIso8601String(),
                ],
            ],
            'active_claims' => [],
        ]);

        $room->participants()->createMany([
            [
                'kind' => 'agent',
                'name' => 'Claude Code - User 1',
                'user_name' => 'User 1',
                'account_email' => $data['owner_email'] ?? 'bryankunhahaha@gmail.com',
                'device_name' => 'User-1-Computer',
                'execution_mode' => 'local_device',
                'provider' => 'claude',
                'model' => 'claude-sonnet-5-5-high',
                'avatar' => 'A1',
                'color' => '#ff5722',
                'role_prompt' => 'Agent 1 operating on User 1\'s local computer.',
                'status' => 'idle',
                'current_thought' => 'Waiting for prompt in group chat',
                'current_action' => 'Idle on User-1-Computer',
                'thinking_steps' => ['Registered in shared memory'],
                'claimed_files' => [],
                'local_modified_files' => [],
                'last_seen_at' => now(),
            ],
            [
                'kind' => 'agent',
                'name' => 'Antigravity - User 2',
                'user_name' => 'User 2',
                'account_email' => 'user2@team.dev',
                'device_name' => 'User-2-Computer',
                'execution_mode' => 'local_device',
                'provider' => 'antigravity',
                'model' => 'gemini-3.8-flash-high',
                'avatar' => 'A2',
                'color' => '#00d2ff',
                'role_prompt' => 'Agent 2 operating on User 2\'s local computer.',
                'status' => 'idle',
                'current_thought' => 'Waiting for peer agent communication',
                'current_action' => 'Idle on User-2-Computer',
                'thinking_steps' => ['Registered in shared memory'],
                'claimed_files' => [],
                'local_modified_files' => [],
                'last_seen_at' => now(),
            ],
        ]);

        return $room->load(['participants', 'invites', 'messages', 'localTasks']);
    }

    public function upsertMemory(ConcordRoom $room, array $entry, bool $postMessage = true): array
    {
        $memories = collect($room->shared_memory ?? []);
        $key = trim($entry['key'] ?? 'context_note');

        $record = [
            'id' => $entry['id'] ?? 'mem_'.Str::lower(Str::random(8)),
            'key' => $key,
            'category' => $entry['category'] ?? 'context',
            'content' => $entry['content'] ?? '',
            'author_agent' => $entry['author_agent'] ?? 'Agent',
            'author_user' => $entry['author_user'] ?? 'Operator',
            'updated_at' => now()->toIso8601String(),
        ];

        $index = $memories->search(fn (array $item) => is_array($item) && ($item['key'] ?? '') === $key);
        if ($index !== false) {
            $memories[$index] = $record;
        } else {
            $memories->push($record);
        }

        $room->update(['shared_memory' => $memories->values()->all()]);

        if ($postMessage) {
            $room->messages()->create([
                'sender_name' => $record['author_agent'],
                'sender_user' => $record['author_user'],
                'sender_kind' => 'memory',
                'sender_icon' => 'MEM',
                'sender_color' => '#a855f7',
                'kind' => 'memory_update',
                'content' => "Updated Shared Memory [{$record['category']}: {$record['key']}]: {$record['content']}",
            ]);
        }

        return $record;
    }

    public function claimFiles(ConcordRoom $room, ConcordParticipant $participant, array $files, string $reason = ''): array
    {
        $claims = collect($room->active_claims ?? []);
        $conflicts = [];

        foreach ($files as $file) {
            $existing = $claims->first(fn (array $c) => ($c['file'] ?? '') === $file && ($c['participant_id'] ?? '') !== $participant->id);
            if ($existing) {
                $conflicts[] = [
                    'file' => $file,
                    'owned_by_agent' => $existing['agent_name'],
                    'owned_by_user' => $existing['user_name'],
                    'device' => $existing['device_name'],
                ];
            }
        }

        if (! empty($conflicts)) {
            $conflictFiles = collect($conflicts)->map(fn ($c) => "{$c['file']} (owned by {$c['owned_by_agent']} on {$c['device']})")->implode(', ');
            $room->messages()->create([
                'participant_id' => $participant->id,
                'sender_name' => $participant->name,
                'sender_user' => $participant->user_name,
                'sender_device' => $participant->device_name,
                'sender_kind' => $participant->provider,
                'sender_icon' => 'WARN',
                'sender_color' => '#ffb300',
                'model' => $participant->model,
                'kind' => 'claim',
                'content' => "Overlap detected before local edit: {$conflictFiles}. Negotiating scope in group chat.",
            ]);

            return ['ok' => false, 'conflicts' => $conflicts];
        }

        foreach ($files as $file) {
            $claims = $claims->reject(fn (array $c) => ($c['file'] ?? '') === $file);
            $claims->push([
                'file' => $file,
                'participant_id' => $participant->id,
                'agent_name' => $participant->name,
                'user_name' => $participant->user_name,
                'device_name' => $participant->device_name,
                'reason' => $reason,
                'claimed_at' => now()->toIso8601String(),
            ]);
        }

        $room->update(['active_claims' => $claims->values()->all()]);
        $participant->update([
            'claimed_files' => array_values(array_unique([...($participant->claimed_files ?? []), ...$files])),
            'current_action' => 'Claimed local files: '.implode(', ', $files),
            'last_seen_at' => now(),
        ]);

        $room->messages()->create([
            'participant_id' => $participant->id,
            'sender_name' => $participant->name,
            'sender_user' => $participant->user_name,
            'sender_device' => $participant->device_name,
            'sender_kind' => $participant->provider,
            'sender_icon' => 'LOCK',
            'sender_color' => $participant->color ?: '#00e676',
            'model' => $participant->model,
            'kind' => 'claim',
            'local_changes' => array_map(fn ($f) => ['file' => $f, 'action' => 'claimed', 'device' => $participant->device_name], $files),
            'content' => "Claimed files on {$participant->device_name}: ".implode(', ', $files).($reason ? " - {$reason}" : ''),
        ]);

        return ['ok' => true, 'conflicts' => [], 'claims' => $room->active_claims];
    }

    public function sendPrompt(ConcordRoom $room, array $data): ConcordRoom
    {
        $senderName = trim($data['sender_name'] ?? 'Operator');
        $senderUser = trim($data['sender_user'] ?? $senderName);
        $senderDevice = trim($data['sender_device'] ?? 'Web Group Chat');
        $text = trim($data['content'] ?? $data['body'] ?? '');
        $targetParticipantId = $data['target_participant_id'] ?? $data['target_agent'] ?? null;
        if ($targetParticipantId === 'all') {
            $targetParticipantId = null;
        }
        $autoHandoff = (bool) ($data['auto_handoff'] ?? true);

        $room->messages()->create([
            'sender_name' => $senderName,
            'sender_user' => $senderUser,
            'sender_device' => $senderDevice,
            'sender_kind' => 'human',
            'sender_icon' => 'USER',
            'sender_color' => '#ff5722',
            'kind' => 'prompt',
            'target_agent' => $targetParticipantId,
            'content' => $text,
        ]);

        $room->update([
            'status' => 'collaborating',
            'status_detail' => 'Agents communicating over VPS Shared Memory & queuing local device changes...',
        ]);

        RunConcordAgentTalk::dispatch($room, $text, $senderName, $targetParticipantId, $autoHandoff);

        return $room->fresh(['participants', 'invites', 'messages', 'localTasks']);
    }

    public function executeMultiAgentTurn(
        ConcordRoom $room,
        string $prompt,
        string $senderName = 'Operator',
        ?string $targetParticipantId = null,
        bool $autoHandoff = true,
    ): void {
        $agents = $room->participants()->where('kind', 'agent')->orderBy('created_at')->get();

        if ($agents->isEmpty()) {
            $room->update([
                'status' => 'active',
                'status_detail' => 'No connected agents in room - add an agent to start.',
            ]);

            return;
        }

        /** @var ConcordParticipant $primary */
        $primary = ($targetParticipantId
            ? ($agents->firstWhere('id', $targetParticipantId) ?? $agents->firstWhere('name', $targetParticipantId))
            : null) ?? $agents->first();
        $peers = $agents->where('id', '!=', $primary->id)->values();

        $this->runSingleAgentStep($room, $primary, $prompt, $senderName, $peers->first());

        if ($autoHandoff && $peers->isNotEmpty()) {
            $lastMsg = $room->messages()->where('participant_id', $primary->id)->latest('id')->first();
            $peerPrompt = $lastMsg
                ? "Message from {$primary->name} ({$primary->user_name} on {$primary->device_name}):\n\"{$lastMsg->content}\"\n\nOriginal request: {$prompt}"
                : $prompt;

            foreach ($peers as $peer) {
                $this->runSingleAgentStep($room, $peer, $peerPrompt, $primary->name, $primary, isHandoffReply: true);
            }
        }

        $room->update([
            'status' => 'active',
            'status_detail' => 'Shared Memory synced - Local device change tasks queued for connected computers',
        ]);
    }

    private function runSingleAgentStep(
        ConcordRoom $room,
        ConcordParticipant $agent,
        string $inputPrompt,
        string $fromSender,
        ?ConcordParticipant $peerAgent = null,
        bool $isHandoffReply = false,
    ): void {
        $step1Thought = $isHandoffReply
            ? "Receiving peer communication from {$fromSender} and reading VPS Shared Memory..."
            : "Reading VPS Shared Memory and analyzing instruction from {$fromSender}...";
        $step2Thought = "Planning local code edits for {$agent->user_name}'s computer ({$agent->device_name})...";
        $step3Thought = $peerAgent && ! $isHandoffReply
            ? "Preparing coordination message & contract handoff for {$peerAgent->name} ({$peerAgent->device_name})..."
            : 'Synchronizing local diff manifest & updating VPS Shared Memory...';

        $agent->update([
            'status' => 'thinking',
            'current_thought' => $step1Thought,
            'current_action' => "Thinking on VPS Shared Context - Target device: {$agent->device_name}",
            'thinking_steps' => [$step1Thought, $step2Thought, $step3Thought],
            'last_seen_at' => now(),
        ]);

        $room->update([
            'status' => 'collaborating',
            'status_detail' => "[THINKING] {$agent->name} ({$agent->user_name}) is reasoning: {$step1Thought}",
        ]);

        $memoryText = collect($room->fresh()->shared_memory ?? [])
            ->filter(fn ($m) => is_array($m) && isset($m['key']))
            ->map(fn (array $m) => "- [{$m['category']}] {$m['key']}: {$m['content']} (by {$m['author_agent']})")
            ->implode("\n") ?: 'None yet.';

        $recentChat = $room->messages()
            ->latest('id')
            ->limit(8)
            ->get()
            ->reverse()
            ->map(fn ($m) => "[{$m->sender_name} @ {$m->sender_device}]: {$m->content}")
            ->implode("\n");

        $peerContext = $peerAgent
            ? "Your peer agent in this group chat is {$peerAgent->name} (owned by {$peerAgent->user_name} on device {$peerAgent->device_name})."
            : 'You are collaborating in the shared group chat.';

        $llmPrompt = "You are {$agent->name}, an AI coding agent owned by {$agent->user_name}, executing local code changes on {$agent->device_name}.\n"
            ."Role: {$agent->role_prompt}\n"
            ."{$peerContext}\n\n"
            ."=== VPS SHARED MEMORY BANK ===\n{$memoryText}\n\n"
            ."=== RECENT GROUP CHAT CONTEXT ===\n{$recentChat}\n\n"
            ."=== INCOMING MESSAGE FROM {$fromSender} ===\n{$inputPrompt}\n\n"
            ."Respond in the group chat without using any emojis. Include:\n"
            ."1. A brief 'THOUGHT:' line explaining your reasoning.\n"
            ."2. A 'LOCAL_FILES:' comma-separated list of 1-3 file paths you are editing on {$agent->device_name}.\n"
            ."3. A 'MEMORY_NOTE:' one-sentence contract/decision to store in the VPS Shared Memory.\n"
            ."4. Your direct response to the group chat (and to {$peerAgent?->name} if coordinating across devices). Keep it concise and concrete.";

        $started = microtime(true);
        [$rawAnswer, $usedModel] = $this->cascade->ask($llmPrompt, $agent->model, $agent->use_openrouter, 75);
        $elapsed = round(microtime(true) - $started, 1);

        [$thought, $localFiles, $memoryNote, $cleanBody] = $this->parseStructuredAgentReply($rawAnswer, $agent);

        $thinkingTrace = array_values(array_filter([
            $step1Thought,
            $thought ?: $step2Thought,
            "Target local machine: {$agent->device_name} ({$agent->user_name})",
            $step3Thought,
        ]));

        if ($memoryNote !== '') {
            $this->upsertMemory($room, [
                'key' => Str::slug($agent->user_name.'_'.$agent->name, '_').'_state',
                'category' => $isHandoffReply ? 'peer_sync' : 'contract',
                'content' => $memoryNote,
                'author_agent' => $agent->name,
                'author_user' => $agent->user_name,
            ], postMessage: false);
        }

        $localChanges = array_map(fn (string $file) => [
            'file' => $file,
            'action' => 'edit_on_local_device',
            'device' => $agent->device_name,
            'user' => $agent->user_name,
            'instructions' => $cleanBody,
        ], $localFiles);

        $task = $room->localTasks()->create([
            'target_participant_id' => $agent->id,
            'target_agent_name' => $agent->name,
            'target_device' => $agent->device_name,
            'from_sender' => $fromSender,
            'kind' => $isHandoffReply ? 'agent_handoff' : 'local_edit',
            'instructions' => $cleanBody,
            'context_memory_snapshot' => $room->fresh()->shared_memory,
            'file_patches' => $localChanges,
            'status' => 'queued',
            'modified_files' => $localFiles,
            'result_summary' => "Queued for local execution on {$agent->device_name} ({$agent->user_name})",
        ]);

        $toolCalls = [
            [
                'tool' => 'SyncSharedMemory',
                'target' => "VPS Room Memory ({$room->title})",
                'output' => $memoryNote ?: 'Context window synchronized',
            ],
            [
                'tool' => 'LocalDeviceEdit',
                'target' => "{$agent->device_name}: ".implode(', ', $localFiles),
                'output' => "Task #{$task->id} dispatched to {$agent->user_name}'s local computer",
            ],
        ];

        $room->messages()->create([
            'participant_id' => $agent->id,
            'sender_name' => $agent->name,
            'sender_user' => $agent->user_name,
            'sender_device' => $agent->device_name,
            'sender_kind' => $agent->provider,
            'sender_icon' => 'AG',
            'sender_color' => $agent->color ?: '#ff5722',
            'model' => $usedModel,
            'kind' => $isHandoffReply ? 'handoff' : 'agent_talk',
            'target_agent' => $peerAgent?->name,
            'thinking_text' => implode("\n", $thinkingTrace),
            'tool_calls' => $toolCalls,
            'local_changes' => $localChanges,
            'content' => $cleanBody,
            'elapsed_sec' => $elapsed,
        ]);

        $agent->update([
            'status' => 'idle',
            'current_thought' => $thought ?: "Completed turn in {$elapsed}s - changes queued for {$agent->device_name}",
            'current_action' => "Edited on {$agent->device_name}: ".implode(', ', $localFiles),
            'thinking_steps' => $thinkingTrace,
            'local_modified_files' => array_values(array_unique([...($agent->local_modified_files ?? []), ...$localFiles])),
            'last_seen_at' => now(),
        ]);
    }

    private function parseStructuredAgentReply(string $raw, ConcordParticipant $agent): array
    {
        $thought = '';
        $localFiles = [];
        $memoryNote = '';
        $bodyLines = [];

        foreach (explode("\n", str_replace("\r", '', $raw)) as $line) {
            $trimmed = trim($line);
            if (preg_match('/^(?:\*\*)?THOUGHT:(?:\*\*)?\s*(.+)$/i', $trimmed, $m)) {
                $thought = trim($m[1]);
                continue;
            }
            if (preg_match('/^(?:\*\*)?LOCAL_FILES:(?:\*\*)?\s*(.+)$/i', $trimmed, $m)) {
                $parsed = array_filter(array_map(fn ($f) => trim($f, " `\"'"), explode(',', $m[1])));
                if (! empty($parsed)) {
                    $localFiles = array_values($parsed);
                }
                continue;
            }
            if (preg_match('/^(?:\*\*)?MEMORY_NOTE:(?:\*\*)?\s*(.+)$/i', $trimmed, $m)) {
                $memoryNote = trim($m[1]);
                continue;
            }
            $bodyLines[] = $line;
        }

        $cleanBody = trim(implode("\n", $bodyLines)) ?: trim($raw);

        if (empty($localFiles)) {
            $localFiles = $agent->provider === 'antigravity'
                ? ['src/components/SyncedView.tsx', 'src/api/client.ts']
                : ['src/services/apiContract.ts', 'src/controllers/mainController.ts'];
        }

        if ($memoryNote === '') {
            $memoryNote = Str::limit(preg_replace('/\s+/', ' ', $cleanBody), 140);
        }

        return [$thought, $localFiles, $memoryNote, $cleanBody];
    }

    public function generateConnectorScript(ConcordRoom $room, string $baseUrl, ?string $participantId = null): string
    {
        $shareToken = $room->share_token;
        $mcpToken = $room->mcp_token;
        $roomTitle = addslashes($room->title);

        return <<<BASH
#!/usr/bin/env bash
# ==============================================================================
# CONCORD LOCAL DEVICE CONNECTOR - {$roomTitle}
# Central Shared Memory & Group Chat: {$baseUrl}
# File Edits & Agent Execution: THIS LOCAL COMPUTER (NOT THE VPS)
# ==============================================================================
set -euo pipefail

HUB_URL="{$baseUrl}"
SHARE_TOKEN="{$shareToken}"
MCP_TOKEN="{$mcpToken}"
PARTICIPANT_ID="{$participantId}"
USER_NAME="\${CONCORD_USER:-\$(whoami)}"
DEVICE_NAME="\${CONCORD_DEVICE:-\$(hostname)}"
AGENT_CLI="\${CONCORD_AGENT:-claude}"

echo "[CONNECT] Connecting local computer [\${DEVICE_NAME}] (User: \${USER_NAME}) to Concord Room: {$roomTitle}..."

# 1. Configure Remote Concord MCP in current repository so local Claude Code / Antigravity / Cursor share VPS memory
mkdir -p .agents .cursor
cat > .mcp.json <<EOF
{
  "mcpServers": {
    "concord-shared-memory": {
      "type": "http",
      "url": "\${HUB_URL}/api/concord/mcp/\${MCP_TOKEN}"
    }
  }
}
EOF
cp .mcp.json .agents/mcp_config.json 2>/dev/null || true
cp .mcp.json .cursor/mcp.json 2>/dev/null || true

# 2. Register or heartbeat this local computer in the VPS Group Chat room
REG_JSON=\$(curl -s -X POST "\${HUB_URL}/api/concord/shared/\${SHARE_TOKEN}/register-device" \\
  -H "Content-Type: application/json" \\
  -d "{\\"participant_id\\":\\"\${PARTICIPANT_ID}\\",\\"user_name\\":\\"\${USER_NAME}\\",\\"device_name\\":\\"\${DEVICE_NAME}\\",\\"provider\\":\\"\${AGENT_CLI}\\"}")

if [ -z "\${PARTICIPANT_ID}" ]; then
  PARTICIPANT_ID=\$(printf '%s' "\${REG_JSON}" | python3 -c "import sys,json; print(json.load(sys.stdin).get('participant',{}).get('id',''))" 2>/dev/null || true)
fi

echo "[OK] Registered on VPS Shared Memory Hub! Participant ID: \${PARTICIPANT_ID}"
echo "[DIR] Local working directory: \$(pwd)"
echo "[LISTEN] Listening for group chat prompts & peer agent handoffs to execute locally on THIS computer..."

while true; do
  TASK_RESP=\$(curl -s "\${HUB_URL}/api/concord/shared/\${SHARE_TOKEN}/tasks/pull?participant_id=\${PARTICIPANT_ID}" || true)
  TASK_ID=\$(printf '%s' "\${TASK_RESP}" | python3 -c "import sys,json; d=json.load(sys.stdin); print((d.get('task') or {}).get('id',''))" 2>/dev/null || true)

  if [ -n "\${TASK_ID}" ]; then
    INSTRUCTIONS=\$(printf '%s' "\${TASK_RESP}" | python3 -c "import sys,json; print(json.load(sys.stdin)['task']['instructions'])")
    echo "[PULL] Received task #\${TASK_ID} from VPS Shared Memory! Executing locally on \${DEVICE_NAME}..."

    curl -s -X POST "\${HUB_URL}/api/concord/shared/\${SHARE_TOKEN}/heartbeat" \\
      -H "Content-Type: application/json" \\
      -d "{\\"participant_id\\":\\"\${PARTICIPANT_ID}\\",\\"status\\":\\"editing_local\\",\\"current_thought\\":\\"Executing changes locally on \${DEVICE_NAME}...\\",\\"current_action\\":\\"Running local \${AGENT_CLI} in \$(basename \$(pwd))\\"}" >/dev/null

    if command -v "\${AGENT_CLI}" >/dev/null 2>&1; then
      "\${AGENT_CLI}" -p "\${INSTRUCTIONS}" || true
    fi

    MODIFIED=\$(git status --porcelain 2>/dev/null | awk '{print \$2}' | paste -sd, - || echo "")
    curl -s -X POST "\${HUB_URL}/api/concord/shared/\${SHARE_TOKEN}/tasks/\${TASK_ID}/complete" \\
      -H "Content-Type: application/json" \\
      -d "{\\"participant_id\\":\\"\${PARTICIPANT_ID}\\",\\"modified_files\\":\\"\${MODIFIED}\\",\\"summary\\":\\"Applied changes locally on \${DEVICE_NAME}\\"}" >/dev/null

    echo "[OK] Local edits completed on \${DEVICE_NAME} and synced to VPS Shared Memory!"
  fi
  sleep 2
done
BASH;
    }
}
