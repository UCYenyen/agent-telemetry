<?php

namespace App\Services;

use App\Models\AgentSession;
use App\Models\ConcordLocalTask;
use App\Models\ConcordParticipant;
use App\Models\ConcordRoom;
use Illuminate\Support\Str;

class ConcordHubService
{
    /**
     * SECURITY GUARANTEE:
     * ConcordHubService NEVER executes any CLI binary, shell command, or filesystem operation on the VPS.
     * It only stores shared context and relays messages; all workspace edits happen on each
     * user's own computer, done by the agent that user connected.
     */

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

    /**
     * The group chat that belongs to a deployed VPS session, plus the session's own agent seat in it.
     */
    public function createSessionRoom(string $title, string $ownerEmail, string $sessionName): ConcordRoom
    {
        $room = $this->createRoom([
            'title' => $title,
            'owner_email' => $ownerEmail,
            'description' => "Group chat for the VPS session {$title}. Invite people by email; their agents connect through this group's MCP link.",
        ]);

        $room->participants()->create([
            'kind' => 'agent',
            'name' => "{$title} (VPS)",
            'user_name' => $title,
            'account_email' => strtolower($ownerEmail),
            'device_name' => self::vpsDevice($sessionName),
            'execution_mode' => 'mcp_connected',
            'provider' => 'vps',
            'model' => 'claude',
            'avatar' => 'VPS',
            'color' => '#ff5722',
            'role_prompt' => 'Claude Code session running on the VPS',
            'status' => 'idle',
            'last_seen_at' => now(),
        ]);

        return $room;
    }

    public static function vpsDevice(string $sessionName): string
    {
        return "vps-{$sessionName}";
    }

    /**
     * Inline --mcp-config and --settings JSON for a Claude Code session launched on this host,
     * so it joins its group with the same MCP tools and activity hooks a laptop gets from the connector.
     *
     * @return array{0: string, 1: string}
     */
    public function vpsClaudeConfig(ConcordRoom $room, string $ownerEmail, string $sessionName, string $title): array
    {
        $url = rtrim((string) config('agenthost.hub_url'), '/')."/api/concord/mcp/{$room->mcp_token}?".http_build_query([
            'user_email' => strtolower($ownerEmail),
            'user_name' => $title,
            'device_name' => self::vpsDevice($sessionName),
        ]);
        $events = str_replace('?', '/events?', $url);
        $post = "curl -s -m 3 -X POST -H 'Content-Type: application/json' --data-binary @- '{$events}'";
        $report = ['type' => 'command', 'command' => "{$post} >/dev/null 2>&1 || true", 'timeout' => 5];
        $stop = ['type' => 'command', 'command' => str_replace('-s -m 3', '-sf -m 8', $post).' 2>/dev/null || true', 'timeout' => 10];

        $mcp = ['mcpServers' => ['concord' => ['type' => 'http', 'url' => $url]]];
        $settings = ['hooks' => [
            'UserPromptSubmit' => [['hooks' => [$stop]]],
            'PreToolUse' => [['matcher' => '*', 'hooks' => [$report]]],
            'Stop' => [['hooks' => [$stop]]],
        ]];

        return [json_encode($mcp, JSON_UNESCAPED_SLASHES), json_encode($settings, JSON_UNESCAPED_SLASHES)];
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

    /**
     * Posts a chat message and queues it for the real connected agents. Nothing replies on an agent's behalf.
     *
     * A copy becomes a runnable "prompt" for agents owned by the signed-in sender (verified_email
     * from the Agent Host session), and for agents whose owner switched on "take tasks from
     * teammates". Every other agent gets a plain "message": context it reads but never runs, so
     * room members can't execute work on each other's computers without the owner's consent.
     */
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
        $fromAgent = (bool) ($data['from_agent'] ?? false);
        $senderParticipantId = $data['participant_id'] ?? null;
        $verifiedEmail = $fromAgent ? '' : strtolower(trim((string) ($data['verified_email'] ?? '')));

        $room->messages()->create([
            'participant_id' => $fromAgent ? $senderParticipantId : null,
            'sender_name' => $senderName,
            'sender_user' => $senderUser,
            'sender_device' => $senderDevice,
            'sender_kind' => $fromAgent ? 'agent' : 'human',
            'sender_icon' => $fromAgent ? 'AG' : 'USER',
            'sender_color' => '#ff5722',
            'kind' => $fromAgent ? 'agent_talk' : 'prompt',
            'target_agent' => $targetParticipantId,
            'content' => $text,
        ]);

        $recipients = $room->participants()
            ->where('kind', 'agent')
            ->when($targetParticipantId, fn ($q) => $q->where(fn ($q) => $q->whereKey($targetParticipantId)->orWhere('name', $targetParticipantId)))
            ->when($fromAgent && $senderParticipantId, fn ($q) => $q->whereKeyNot($senderParticipantId))
            ->orderBy('created_at')
            ->get();

        foreach ($recipients as $agent) {
            $isOwner = $verifiedEmail !== '' && strtolower((string) $agent->account_email) === $verifiedEmail;
            $runnable = $isOwner || $agent->accepts_team_tasks;

            $task = $room->localTasks()->create([
                'target_participant_id' => $agent->id,
                'target_agent_name' => $agent->name,
                'target_device' => $agent->device_name,
                'from_sender' => $senderName,
                'from_email' => $verifiedEmail ?: null,
                'kind' => $runnable ? 'prompt' : 'message',
                'instructions' => $text,
                'status' => 'queued',
                'result_summary' => "From {$senderName} ({$senderDevice})",
            ]);

            // A VPS session is typed into right away; its Stop hook posts the reply when it finishes.
            if ($runnable && $agent->agent_session_id && ($session = AgentSession::find($agent->agent_session_id))) {
                if ($this->claimTasks($agent)->isNotEmpty()) {
                    $agent->recordActivity('prompt', "Prompt from {$senderName} via Concord", $text);
                    app(AgentSessionManager::class)->prompt($session, $text);
                }
            }
        }

        return $room->fresh(['participants', 'invites', 'messages', 'localTasks']);
    }

    /**
     * Tells the rest of the group what an agent is doing: optionally a line in the chat, and a
     * context note queued for every other agent, which it receives with its next briefing.
     */
    public function shareWithGroup(ConcordRoom $room, ConcordParticipant $agent, ?string $chat, string $context): void
    {
        if ($chat !== null && trim($chat) !== '') {
            $room->messages()->create([
                'participant_id' => $agent->id,
                'sender_name' => $agent->user_name,
                'sender_user' => $agent->user_name,
                'sender_device' => $agent->device_name,
                'sender_kind' => 'agent',
                'sender_icon' => 'AG',
                'sender_color' => $agent->color ?: '#ff5722',
                'kind' => 'agent_status',
                'content' => Str::limit(trim($chat), 1500),
            ]);
        }

        $others = $room->participants()->where('kind', 'agent')->whereKeyNot($agent->id)->get();
        foreach ($others as $other) {
            $room->localTasks()->create([
                'target_participant_id' => $other->id,
                'target_agent_name' => $other->name,
                'target_device' => $other->device_name,
                'from_sender' => "{$agent->user_name} ({$agent->device_name})",
                'kind' => 'context',
                'instructions' => Str::limit(trim($context), 800),
                'status' => 'queued',
            ]);
        }
    }

    /**
     * What an agent is told at the start of each turn: who else is in the group, how to split
     * work with them, and what the group said or did since its last turn.
     */
    public function coordinationBrief(ConcordRoom $room, ConcordParticipant $agent): string
    {
        $others = $room->participants()->where('kind', 'agent')->whereKeyNot($agent->id)->orderBy('created_at')->get();
        $updates = $this->claimTasks($agent, ['message', 'context'], 20);

        if ($others->isEmpty() && $updates->isEmpty()) {
            return '';
        }

        $lines = ["You are {$agent->user_name}'s agent on {$agent->device_name} in the Concord group \"{$room->title}\"."];
        if ($others->isNotEmpty()) {
            $lines[] = 'Other agents in this group, each working on its own person\'s computer:';
            foreach ($others as $other) {
                $lines[] = "- {$other->user_name} on {$other->device_name} (id {$other->id})"
                    .($other->accepts_team_tasks ? ', takes tasks from teammates' : ', reads your messages as context only');
            }
            $lines[] = 'Only change files on this computer. If the request includes work that belongs to another member, '
                .'decide which part is yours, send each agent its part with the concord send_group_message tool '
                .'(target_agent = its id), and record the split and any shared contracts with write_shared_memory. '
                .'Call get_shared_memory first to build on what the group already decided.';
        }

        if ($updates->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'New from your group since your last turn:';
            foreach ($updates as $update) {
                $lines[] = "- {$update->from_sender}: ".Str::limit(preg_replace('/\s+/', ' ', (string) $update->instructions), 600);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Atomically hands queued tasks to one agent, so a hook, the channel bridge and an MCP
     * pull never deliver the same task twice. Prompts become "running" until the agent's turn ends.
     *
     * @return \Illuminate\Support\Collection<int, ConcordLocalTask>
     */
    public function claimTasks(ConcordParticipant $agent, array $kinds = ['prompt'], int $limit = 5)
    {
        $candidates = ConcordLocalTask::query()
            ->where('target_participant_id', $agent->id)
            ->where('status', 'queued')
            ->whereIn('kind', $kinds)
            ->oldest('created_at')
            ->limit($limit)
            ->get();

        return $candidates->filter(function (ConcordLocalTask $task) {
            $status = $task->kind === 'prompt' ? 'running' : 'read';
            $claimed = ConcordLocalTask::query()
                ->whereKey($task->id)
                ->where('status', 'queued')
                ->update(['status' => $status, 'delivered_at' => now()]);

            if ($claimed === 1) {
                $task->status = $status;

                return true;
            }

            return false;
        })->values();
    }

    /**
     * Marks the agent's running prompts done and posts its final answer to the room chat.
     */
    public function finishRunningPrompts(ConcordRoom $room, ConcordParticipant $agent, string $reply): int
    {
        $running = $room->localTasks()
            ->where('target_participant_id', $agent->id)
            ->where('kind', 'prompt')
            ->where('status', 'running')
            ->get();

        if ($running->isEmpty()) {
            return 0;
        }

        $reply = trim($reply) !== '' ? Str::limit(trim($reply), 4000) : 'Finished.';
        $room->localTasks()->whereIn('id', $running->modelKeys())->update([
            'status' => 'completed',
            'reply' => $reply,
            'completed_at' => now(),
        ]);

        $room->messages()->create([
            'participant_id' => $agent->id,
            'sender_name' => $agent->user_name,
            'sender_user' => $agent->user_name,
            'sender_device' => $agent->device_name,
            'sender_kind' => 'agent',
            'sender_icon' => 'AG',
            'sender_color' => $agent->color ?: '#ff5722',
            'kind' => 'agent_talk',
            'content' => $reply,
        ]);

        return $running->count();
    }

    public function generateConnectorScript(
        ConcordRoom $room,
        string $baseUrl,
        ?string $participantId = null,
        ?string $userEmail = null,
        ?string $userName = null,
        string $channel = ''
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
        $defaultChannel = escapeshellarg(in_array($channel, ['yes', 'no'], true) ? $channel : '');

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
DEFAULT_CHANNEL={$defaultChannel}
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

# Channel bridge: lets a prompt sent from the Concord UI wake an idle Claude Code terminal session.
mkdir -p .concord
echo '*' > .concord/.gitignore
curl -fsS "\${HUB_URL}/concord-channel.mjs" -o .concord/channel.mjs || echo "[SKIP] Could not download the channel bridge"

python3 - "\${MCP_URL}" <<'PY'
import json, os, sys

url = sys.argv[1]
inbox_url = url.replace("?", "/inbox?", 1)
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
    if path == ".mcp.json" and os.path.exists(".concord/channel.mjs"):
        data["mcpServers"]["concord-channel"] = {"command": "node", "args": [".concord/channel.mjs", inbox_url]}
    with open(path, "w") as handle:
        json.dump(data, handle, indent=2)
    print(f"[MCP] Registered concord server in {path}")

# Claude Code hooks report each prompt, tool call and finished turn to the room's agent board.
# They live in .claude/settings.local.json (not committed) and only send summaries, never file contents.
# The prompt and Stop hooks also print the hub's answer: on a new prompt it briefs Claude on the group
# (other agents, how to split work, what they did since), and on Stop it hands over the next task
# sent from Concord, whose final reply is posted back to the group.
events_url = url.replace("?", "/events?", 1)
post = "curl -s -m 3 -X POST -H 'Content-Type: application/json' --data-binary @- '" + events_url + "'"
report_hook = {"type": "command", "command": post + " >/dev/null 2>&1 || true", "timeout": 5}
stop_hook = {"type": "command", "command": post.replace("-s -m 3", "-sf -m 8") + " 2>/dev/null || true", "timeout": 10}
path = ".claude/settings.local.json"
os.makedirs(".claude", exist_ok=True)
settings = {}
if os.path.exists(path):
    try:
        with open(path) as handle:
            settings = json.load(handle)
    except (OSError, ValueError):
        settings = None
        print(f"[SKIP] {path} is not valid JSON; Claude Code activity reporting not installed")
if settings is not None:
    hooks = settings.setdefault("hooks", {})
    for event, matcher, hook in (("UserPromptSubmit", None, stop_hook), ("PreToolUse", "*", report_hook), ("Stop", None, stop_hook)):
        entries = [e for e in hooks.get(event, []) if "/api/concord/mcp/" not in json.dumps(e)]
        entry = {"hooks": [hook]}
        if matcher:
            entry["matcher"] = matcher
        entries.append(entry)
        hooks[event] = entries
    with open(path, "w") as handle:
        json.dump(settings, handle, indent=2)
    print(f"[MCP] Claude Code activity reporting enabled in {path}")
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

# 3. Instant prompts: a "concord" command that starts Claude Code with the Concord channel,
#    so prompts sent from Concord start right away even while the session sits idle.
CHANNEL_CHOICE="\${CONCORD_CHANNEL:-\$DEFAULT_CHANNEL}"
if [ -z "\${CHANNEL_CHOICE}" ] && { : < /dev/tty; } 2>/dev/null; then
  printf "Let prompts from Concord start right away, even while Claude Code is idle? [Y/n] " > /dev/tty
  read -r CHANNEL_CHOICE < /dev/tty || CHANNEL_CHOICE=""
  CHANNEL_CHOICE="\${CHANNEL_CHOICE:-yes}"
fi
CHANNEL_ON=0
case "\${CHANNEL_CHOICE}" in
  y|Y|yes|Yes|YES|1)
    mkdir -p "\${HOME}/.local/bin"
    cat > "\${HOME}/.local/bin/concord" <<'LAUNCHER'
#!/usr/bin/env bash
# Starts Claude Code with the Concord channel, so prompts sent from Concord reach it even when idle.
# Run it in a project folder connected to a Concord group; any arguments are passed on to claude.
if ! grep -qs '"concord-channel"' .mcp.json; then
  echo "[concord] This folder is not connected to a Concord group, so starting plain Claude Code." >&2
  exec claude "\$@"
fi
exec claude --dangerously-load-development-channels server:concord-channel "\$@"
LAUNCHER
    chmod +x "\${HOME}/.local/bin/concord"
    CHANNEL_ON=1
    ;;
esac

echo "[OK] Connected \${DEVICE_NAME} to \${ROOM_TITLE}. Participant ID: \${PARTICIPANT_ID}"
echo ""
echo "Prompts you send to this agent from Concord (signed in as \${USER_EMAIL}) run in your open"
echo "Claude Code session (terminal or VS Code) as soon as it finishes its current turn."
if [ "\${CHANNEL_ON}" = 1 ]; then
  echo ""
  echo "Instant prompts: ON. In this folder, start Claude Code with"
  echo "    concord"
  echo "instead of claude (claude --continue becomes concord --continue). Prompts from Concord then"
  echo "start straight away, even while it is idle. When Claude Code asks about the development"
  echo "channel, choose 'I am using this for local development'."
  case ":\${PATH}:" in
    *":\${HOME}/.local/bin:"*) ;;
    *) echo '  First add ~/.local/bin to your PATH (for example in ~/.bashrc):  export PATH="\$HOME/.local/bin:\$PATH"' ;;
  esac
else
  echo "Instant prompts: OFF. Re-run this command and answer yes to turn them on."
fi
echo ""
echo "Restart Claude Code (or reload the VS Code window) so it picks up the new hooks."
BASH;
    }
}
