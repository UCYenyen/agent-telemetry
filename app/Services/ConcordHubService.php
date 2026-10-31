<?php

namespace App\Services;

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

    /**
     * Posts a chat message and delivers it to the real connected agents as a queued task,
     * which each agent fetches with the pull_local_tasks MCP tool. Nothing replies on an agent's behalf.
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
            $room->localTasks()->create([
                'target_participant_id' => $agent->id,
                'target_agent_name' => $agent->name,
                'target_device' => $agent->device_name,
                'from_sender' => $senderName,
                'kind' => 'message',
                'instructions' => $text,
                'status' => 'queued',
                'result_summary' => "Message from {$senderName} ({$senderDevice})",
            ]);
        }

        return $room->fresh(['participants', 'invites', 'messages', 'localTasks']);
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

# Claude Code hooks report each prompt, tool call and finished turn to the room's agent board.
# They live in .claude/settings.local.json (not committed) and only send summaries, never file contents.
events_url = url.replace("?", "/events?", 1)
command = "curl -s -m 3 -X POST -H 'Content-Type: application/json' --data-binary @- '" + events_url + "' >/dev/null 2>&1 || true"
hook = {"type": "command", "command": command, "timeout": 5}
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
    for event, matcher in (("UserPromptSubmit", None), ("PreToolUse", "*"), ("Stop", None)):
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
