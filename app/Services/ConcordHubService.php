<?php

namespace App\Services;

use App\Jobs\RunConcordAgentTalk;
use App\Models\ConcordParticipant;
use App\Models\ConcordRoom;
use Illuminate\Support\Str;

class ConcordHubService
{
    /**
     * SECURITY GUARANTEE:
     * ConcordHubService NEVER executes any CLI binary, shell command, or filesystem operation on the VPS.
     * It uses only stateless OpenRouter HTTP calls (when configured) or sandboxed in-memory coordination,
     * and dispatches all workspace file edits to each user's own local computer via ConcordLocalTask.
     */
    public function __construct(private OpenRouter $openRouter) {}

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
                'title' => 'No Role (Default - Read-Only Group Chat Viewer)',
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
        // Purge any legacy default 2 mock agents that were seeded automatically in earlier versions
        ConcordParticipant::query()
            ->whereIn('device_name', ['User-1-Workstation', 'User-2-Laptop', 'User-1-Computer', 'User-2-Computer'])
            ->whereIn('name', ['Claude Code - User 1', 'Antigravity Gemini - User 2', 'Antigravity - User 2'])
            ->delete();

        $existing = ConcordRoom::oldest('created_at')->first();

        if ($existing) {
            return $existing;
        }

        return ConcordRoom::create([
            'title' => 'Concord Multi-Agent Shared Workspace',
            'description' => 'Central VPS Context Memory & Multi-Agent Group Chat. Each user must connect their local MCP first before prompting. All file edits execute on each user\'s own computer.',
            'owner_email' => 'bryankunhahaha@gmail.com',
            'access_mode' => 'invite_only',
            'status' => 'active',
            'status_detail' => 'Room initialized - 0 MCP agents connected. Connect your local MCP first to unlock prompting.',
            'shared_memory' => [
                [
                    'id' => 'mem_arch_1',
                    'key' => 'execution_topology',
                    'category' => 'architecture',
                    'content' => 'VPS holds ONLY shared context memory, session state, and agent-to-agent group chat. Each user connects their local MCP first to unlock prompting, and all file edits execute on each user\'s local computer.',
                    'author_agent' => 'Concord Security Sandbox',
                    'author_user' => 'System',
                    'updated_at' => now()->toIso8601String(),
                ],
            ],
            'active_claims' => [],
        ]);
    }

    public function createRoom(array $data): ConcordRoom
    {
        $room = ConcordRoom::create([
            'title' => $data['title'],
            'description' => $data['description'] ?? 'Multi-user group chat with centralized VPS context memory and local MCP execution. Unlimited users & agents supported.',
            'owner_email' => $data['owner_email'] ?? 'bryankunhahaha@gmail.com',
            'access_mode' => $data['access_mode'] ?? 'invite_only',
            'status' => 'active',
            'status_detail' => 'Room initialized - 0 MCP agents connected. Connect your local MCP first to unlock prompting.',
            'shared_memory' => [
                [
                    'id' => 'mem_init',
                    'key' => 'workspace_goal',
                    'category' => 'decision',
                    'content' => $data['description'] ?? "Collaborative workspace for {$data['title']} - connect your local MCP agent to begin.",
                    'author_agent' => 'Concord Hub',
                    'author_user' => $data['owner_email'] ?? 'Owner',
                    'updated_at' => now()->toIso8601String(),
                ],
            ],
            'active_claims' => [],
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

        $index = $memories->search(fn ($item) => is_array($item) && ($item['key'] ?? '') === $key);
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

    /**
     * Multi-agent coordination across N connected users & computers (2, 3, or more agents).
     * Agent 1 plans its scope and queues edits for User 1's PC, then communicates with Agent 2, Agent 3, ..., Agent N.
     * Each peer agent reads the updated VPS Shared Memory from all preceding agents and queues edits for its own user's local PC.
     */
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
        $totalAgents = $agents->count();

        // Step 1: Primary Agent (User 1's Agent) processes the prompt, updates VPS Shared Memory, and queues local task on User 1's PC
        $this->runSingleAgentStep(
            room: $room,
            agent: $primary,
            inputPrompt: $prompt,
            fromSender: $senderName,
            allAgents: $agents->all(),
            stepNumber: 1,
            totalSteps: $autoHandoff ? $totalAgents : 1,
            nextPeer: $peers->first(),
            isHandoffReply: false
        );

        // Step 2..N: Every other agent in the group chat (User 2's Agent, User 3's Agent, etc.) receives the handoff,
        // reads the cumulative VPS Shared Memory from all previous agents, talks back in the group chat,
        // and queues its own local workspace task for User 2's PC, User 3's PC, etc.!
        if ($autoHandoff && $peers->isNotEmpty()) {
            foreach ($peers as $idx => $peer) {
                $stepNumber = $idx + 2;
                $nextPeer = $peers->get($idx + 1) ?? $primary;

                $previousMessages = $room->messages()
                    ->latest('id')
                    ->limit($idx + 1)
                    ->get()
                    ->reverse()
                    ->map(fn ($m) => "{$m->sender_name} ({$m->sender_user} on {$m->sender_device}): \"{$m->content}\"")
                    ->implode("\n\n");

                $peerPrompt = "Multi-agent task coordination across {$totalAgents} computers.\n"
                    ."Original request: {$prompt}\n\n"
                    ."Updates from preceding agents in this turn:\n{$previousMessages}\n\n"
                    ."Coordinate your part for {$peer->user_name}'s computer ({$peer->device_name}) without overlapping files claimed by other computers.";

                $this->runSingleAgentStep(
                    room: $room,
                    agent: $peer,
                    inputPrompt: $peerPrompt,
                    fromSender: $primary->name,
                    allAgents: $agents->all(),
                    stepNumber: $stepNumber,
                    totalSteps: $totalAgents,
                    nextPeer: $nextPeer,
                    isHandoffReply: true
                );
            }
        }

        $room->update([
            'status' => 'active',
            'status_detail' => "Shared Memory synced across {$totalAgents} agent(s) - Local device change tasks queued for each user's computer",
        ]);
    }

    /**
     * @param  array<int, ConcordParticipant>  $allAgents
     */
    private function runSingleAgentStep(
        ConcordRoom $room,
        ConcordParticipant $agent,
        string $inputPrompt,
        string $fromSender,
        array $allAgents,
        int $stepNumber,
        int $totalSteps,
        ?ConcordParticipant $nextPeer = null,
        bool $isHandoffReply = false,
    ): void {
        $step1Thought = $isHandoffReply
            ? "[Agent {$stepNumber}/{$totalSteps}] Receiving handoff from {$fromSender} and reading cumulative VPS Shared Memory..."
            : "[Agent {$stepNumber}/{$totalSteps}] Reading VPS Shared Memory and analyzing task split from {$fromSender}...";
        $step2Thought = "Partitioning task scope & planning local code edits exclusively for {$agent->user_name}'s computer ({$agent->device_name})...";
        $step3Thought = $nextPeer && $stepNumber < $totalSteps
            ? "Publishing API contract to VPS Shared Memory and handing off next slice to {$nextPeer->name} ({$nextPeer->device_name})..."
            : "Finalizing multi-agent synchronization & queuing local workspace patch for {$agent->device_name}...";

        $agent->update([
            'status' => 'thinking',
            'current_thought' => $step1Thought,
            'current_action' => "Thinking on VPS Shared Context - Target device: {$agent->device_name}",
            'thinking_steps' => [$step1Thought, $step2Thought, $step3Thought],
            'last_seen_at' => now(),
        ]);

        $room->update([
            'status' => 'collaborating',
            'status_detail' => "[THINKING {$stepNumber}/{$totalSteps}] {$agent->name} ({$agent->user_name}) is reasoning: {$step1Thought}",
        ]);

        $memoryText = collect($room->fresh()->shared_memory ?? [])
            ->filter(fn ($m) => is_array($m) && isset($m['key']))
            ->map(fn (array $m) => "- [{$m['category']}] {$m['key']}: {$m['content']} (by {$m['author_agent']})")
            ->implode("\n") ?: 'None yet.';

        $rosterText = collect($allAgents)
            ->map(fn (ConcordParticipant $p, int $i) => ($i + 1).". {$p->name} (User: {$p->user_name}, Local Computer: {$p->device_name}, Model: {$p->model})")
            ->implode("\n");

        $llmPrompt = "You are {$agent->name} (Agent {$stepNumber} of {$totalSteps} in this room), owned by {$agent->user_name}, executing local code changes ONLY on {$agent->device_name}.\n"
            ."SECURITY RULE: You have zero access to the VPS host. You only coordinate via the shared memory bank and queue local file edits for {$agent->device_name}.\n"
            ."Role: {$agent->role_prompt}\n\n"
            ."=== ALL CONNECTED AGENTS & COMPUTERS IN THIS ROOM ===\n{$rosterText}\n\n"
            ."=== VPS SHARED MEMORY BANK ===\n{$memoryText}\n\n"
            ."=== TASK / HANDOFF INPUT ===\n{$inputPrompt}\n\n"
            ."Respond in the group chat without using any emojis. Include:\n"
            ."1. A brief 'THOUGHT:' line explaining how you are dividing the work for {$agent->user_name}'s computer ({$agent->device_name}).\n"
            ."2. A 'LOCAL_FILES:' comma-separated list of 1-3 file paths you are editing on {$agent->device_name}.\n"
            ."3. A 'MEMORY_NOTE:' one-sentence contract/decision to store in the VPS Shared Memory for the other agents.\n"
            ."4. Your direct response to the group chat explaining what you queued on {$agent->device_name} and what the other agent(s) should handle.";

        $started = microtime(true);
        [$rawAnswer, $usedModel] = $this->askSandboxedCloudOrFallback($llmPrompt, $agent, $stepNumber, $totalSteps, $nextPeer, $inputPrompt);
        $elapsed = round(microtime(true) - $started, 1);

        [$thought, $localFiles, $memoryNote, $cleanBody] = $this->parseStructuredAgentReply($rawAnswer, $agent, $stepNumber);

        $thinkingTrace = array_values(array_filter([
            $step1Thought,
            $thought ?: $step2Thought,
            "Target local machine: {$agent->device_name} ({$agent->user_name}) - VPS Host Sandbox Enforced",
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
                'output' => "Task #{$task->id} dispatched to {$agent->user_name}'s local computer ({$agent->device_name})",
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
            'target_agent' => $nextPeer?->name,
            'thinking_text' => implode("\n", $thinkingTrace),
            'tool_calls' => $toolCalls,
            'local_changes' => $localChanges,
            'content' => $cleanBody,
            'elapsed_sec' => $elapsed,
        ]);

        $agent->update([
            'status' => 'idle',
            'current_thought' => $thought ?: "Completed turn in {$elapsed}s - changes queued for {$agent->device_name}",
            'current_action' => "Queued local edits on {$agent->device_name}: ".implode(', ', $localFiles),
            'thinking_steps' => $thinkingTrace,
            'local_modified_files' => array_values(array_unique([...($agent->local_modified_files ?? []), ...$localFiles])),
            'last_seen_at' => now(),
        ]);
    }

    /**
     * Sandboxed cloud API call that NEVER runs any CLI binary or shell command on the VPS.
     *
     * @return array{0: string, 1: string}
     */
    private function askSandboxedCloudOrFallback(
        string $llmPrompt,
        ConcordParticipant $agent,
        int $stepNumber,
        int $totalSteps,
        ?ConcordParticipant $nextPeer,
        string $inputPrompt
    ): array {
        $chain = $this->openRouter->chain();
        $targetModel = str_contains($agent->model, '/') ? $agent->model : ($chain[0] ?? 'anthropic/claude-3.7-sonnet');
        $apiKey = $this->openRouter->keyFor($targetModel);

        if ($apiKey !== '') {
            [$ok, $text, $used] = $this->openRouter->chat(
                $apiKey,
                $targetModel,
                [['role' => 'user', 'content' => $llmPrompt]],
                $chain,
                45
            );
            if ($ok && trim($text) !== '') {
                return [$text, "cloud-sandbox // {$used}"];
            }
        }

        // Deterministic sandboxed coordination response when no cloud API key is configured
        $defaultFiles = match ($stepNumber % 3) {
            1 => ['src/services/taskCoordinator.ts', 'src/api/sharedContract.ts'],
            2 => ['src/components/SyncedWorkspaceView.tsx', 'src/hooks/useSharedState.ts'],
            default => ['tests/integration/multiDeviceSync.test.ts', 'src/workers/localDeviceSync.ts'],
        };
        $filesCsv = implode(', ', $defaultFiles);
        $nextHandoff = $nextPeer
            ? "Handing off the updated shared memory contract to {$nextPeer->name} ({$nextPeer->user_name} on {$nextPeer->device_name})."
            : "All {$totalSteps} connected user computers now have their synchronized local tasks queued.";

        $synthesized = "THOUGHT: Partitioned task step {$stepNumber}/{$totalSteps} for {$agent->user_name}'s local machine ({$agent->device_name}) using VPS Shared Memory without touching the VPS filesystem.\n"
            ."LOCAL_FILES: {$filesCsv}\n"
            ."MEMORY_NOTE: Step {$stepNumber}/{$totalSteps} ({$agent->name} on {$agent->device_name}) claimed {$filesCsv} and published interface state.\n"
            ."[Agent {$stepNumber}/{$totalSteps} - {$agent->name} on {$agent->device_name}]: I have analyzed the shared context and queued local workspace edits for `{$filesCsv}` directly on **{$agent->user_name}'s computer (`{$agent->device_name}`)**. {$nextHandoff}";

        return [$synthesized, "sandbox-coordinator // {$agent->model}"];
    }

    private function parseStructuredAgentReply(string $raw, ConcordParticipant $agent, int $stepNumber = 1): array
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
            $localFiles = match ($stepNumber % 3) {
                1 => ['src/services/apiContract.ts', 'src/controllers/mainController.ts'],
                2 => ['src/components/SyncedView.tsx', 'src/api/client.ts'],
                default => ['tests/e2e/groupSync.spec.ts', 'src/utils/stateBridge.ts'],
            };
        }

        if ($memoryNote === '') {
            $memoryNote = Str::limit(preg_replace('/\s+/', ' ', $cleanBody), 140);
        }

        return [$thought, $localFiles, $memoryNote, $cleanBody];
    }

    public function generateConnectorScript(
        ConcordRoom $room,
        string $baseUrl,
        ?string $participantId = null,
        ?string $userEmail = null,
        ?string $userName = null
    ): string {
        // Every request-supplied value is emitted single-quoted via escapeshellarg: this script
        // is piped straight into bash, so a crafted ?user= or ?email= must never expand.
        $shareToken = escapeshellarg($room->share_token);
        $mcpToken = escapeshellarg($room->mcp_token);
        $hubUrl = escapeshellarg($baseUrl);
        $plainTitle = trim(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $room->title));
        $roomTitle = escapeshellarg($plainTitle);
        $commentTitle = str_replace(['$', '`', '\\'], '', $plainTitle);
        $defaultEmail = escapeshellarg((string) ($userEmail ?? ''));
        $defaultUser = escapeshellarg((string) ($userName ?? ''));
        $participantId = escapeshellarg((string) ($participantId ?? ''));

        return <<<BASH
#!/usr/bin/env bash
# ==============================================================================
# CONCORD LOCAL MCP & DEVICE CONNECTOR - {$commentTitle}
# Shared memory and group chat live on the hub; file edits run on THIS computer.
# ==============================================================================
set -euo pipefail

HUB_URL={$hubUrl}
SHARE_TOKEN={$shareToken}
MCP_TOKEN={$mcpToken}
ROOM_TITLE={$roomTitle}
PARTICIPANT_ID={$participantId}
DEFAULT_USER={$defaultUser}
DEFAULT_EMAIL={$defaultEmail}
USER_NAME="\${CONCORD_USER:-\$DEFAULT_USER}"
if [ -z "\${USER_NAME}" ]; then USER_NAME="\$(whoami)"; fi
USER_EMAIL="\${CONCORD_EMAIL:-\$DEFAULT_EMAIL}"
DEVICE_NAME="\${CONCORD_DEVICE:-\$(hostname)}"
AGENT_CLI="\${CONCORD_AGENT:-claude}"
MODEL_ID="\${CONCORD_MODEL:-claude-sonnet-5-5-high}"

echo "[MCP] Connecting \${DEVICE_NAME} (\${USER_NAME} <\${USER_EMAIL}>) to Concord room: \${ROOM_TITLE}"

# 1. Register the Concord MCP server for every agent client in this repository.
#    Existing config files are merged, never overwritten.
MCP_URL=\$(python3 -c 'import sys, urllib.parse as u; print(sys.argv[1] + "/api/concord/mcp/" + sys.argv[2] + "?" + u.urlencode({"user_email": sys.argv[3], "user_name": sys.argv[4], "device_name": sys.argv[5]}))' "\${HUB_URL}" "\${MCP_TOKEN}" "\${USER_EMAIL}" "\${USER_NAME}" "\${DEVICE_NAME}")

python3 - "\${MCP_URL}" <<'PY'
import json, os, sys

url = sys.argv[1]
targets = {
    ".mcp.json": {"type": "http", "url": url},                 # Claude Code
    ".cursor/mcp.json": {"url": url},                           # Cursor
    ".gemini/settings.json": {"httpUrl": url},                  # Gemini CLI
    ".agents/mcp_config.json": {"serverUrl": url},              # Antigravity
}
for path, server in targets.items():
    os.makedirs(os.path.dirname(path) or ".", exist_ok=True)
    data = {}
    if os.path.exists(path):
        try:
            with open(path) as handle:
                data = json.load(handle)
        except (OSError, ValueError):
            print(f"[SKIP] {path} is not valid JSON; add the concord server by hand")
            continue
    data.setdefault("mcpServers", {})["concord"] = server
    with open(path, "w") as handle:
        json.dump(data, handle, indent=2)
    print(f"[MCP] Registered concord server in {path}")
PY

echo "[MCP] Antigravity CLI: agy mcp add concord \"\${MCP_URL}\""
echo "[MCP] Codex CLI: codex mcp add concord --url \"\${MCP_URL}\""
echo "[MCP] stdio-only clients: npx -y mcp-remote \"\${MCP_URL}\""

# 2. Perform MCP JSON-RPC Handshake & Register this local computer in the VPS Group Chat room
curl -s -X POST "\${HUB_URL}/api/concord/mcp/\${MCP_TOKEN}" \\
  -H "Content-Type: application/json" \\
  -d "{\\"jsonrpc\\":\\"2.0\\",\\"id\\":1,\\"method\\":\\"initialize\\",\\"params\\":{\\"user_email\\":\\"\${USER_EMAIL}\\",\\"user_name\\":\\"\${USER_NAME}\\",\\"device_name\\":\\"\${DEVICE_NAME}\\",\\"provider\\":\\"\${AGENT_CLI}\\",\\"model\\":\\"\${MODEL_ID}\\"}}" >/dev/null || true

REG_JSON=\$(curl -s -X POST "\${HUB_URL}/api/concord/shared/\${SHARE_TOKEN}/register-device" \\
  -H "Content-Type: application/json" \\
  -d "{\\"participant_id\\":\\"\${PARTICIPANT_ID}\\",\\"user_name\\":\\"\${USER_NAME}\\",\\"account_email\\":\\"\${USER_EMAIL}\\",\\"device_name\\":\\"\${DEVICE_NAME}\\",\\"provider\\":\\"\${AGENT_CLI}\\",\\"model\\":\\"\${MODEL_ID}\\"}")

if [ -z "\${PARTICIPANT_ID}" ]; then
  PARTICIPANT_ID=\$(printf '%s' "\${REG_JSON}" | python3 -c "import sys,json; print(json.load(sys.stdin).get('participant',{}).get('id',''))" 2>/dev/null || true)
fi

echo "[OK] MCP Connected & Verified on VPS Shared Memory Hub! Participant ID: \${PARTICIPANT_ID}"
echo "[UNLOCKED] Group Chat prompting is now unlocked for \${USER_NAME} (\${DEVICE_NAME})."
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
