<?php

namespace App\Services;

use App\Models\ConcordParticipant;
use App\Models\ConcordRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Remote MCP server for a Concord room, spoken over the Streamable HTTP transport
 * (plain JSON responses, no SSE stream). Clients: Claude Code, Antigravity / Gemini CLI,
 * Codex, Cursor, and anything stdio-only through `npx mcp-remote <url>`.
 */
class ConcordMcpServer
{
    public const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    private const INSTRUCTIONS = <<<'TXT'
You are connected to a Concord room: a shared memory bank and group chat used by several people's coding agents on different computers.
- Call get_shared_memory before starting work, so you build on decisions, API contracts and file claims other agents have already made.
- Call write_shared_memory whenever you settle something other agents depend on (an interface, a naming decision, a file you now own, a finished task). Keep each entry one or two sentences under a stable key.
- When your user gives you a new request, call report_thinking with prompt set to that request (verbatim or a faithful one-line summary), and update current_action as you work, so the room can see what every agent is doing.
- Use send_group_message to talk to the other people and agents in the room.
- All file edits happen on your own computer; the room only holds shared context.
TXT;

    private ?ConcordParticipant $caller = null;

    public function __construct(private ConcordHubService $hub) {}

    public function handle(Request $request, ConcordRoom $room): JsonResponse|Response
    {
        $payload = $request->json()->all();

        if (! is_array($payload) || $payload === []) {
            return $this->json($this->error(null, -32700, 'Parse error: expected a JSON-RPC message'), 400);
        }

        $this->caller = $this->identifyCaller($request, $room);

        // A JSON array is a batch; notifications inside it produce no response entry.
        if (array_is_list($payload)) {
            $responses = array_values(array_filter(array_map(fn ($message) => $this->dispatch($room, $message), $payload)));

            return $responses === [] ? response('', 202) : $this->json($responses);
        }

        $response = $this->dispatch($room, $payload);

        return $response === null ? response('', 202) : $this->json($response);
    }

    /**
     * @return array<string, mixed>|null null for notifications and client responses
     */
    private function dispatch(ConcordRoom $room, mixed $message): ?array
    {
        if (! is_array($message) || ! isset($message['method'])) {
            // Responses to server requests (we never send any) and junk are acknowledged silently.
            return is_array($message) && array_key_exists('id', $message) && ! isset($message['result']) && ! isset($message['error'])
                ? $this->error($message['id'], -32600, 'Invalid request')
                : null;
        }

        $method = (string) $message['method'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        if (! array_key_exists('id', $message)) {
            return null; // notifications/initialized, notifications/cancelled, ...
        }

        $id = $message['id'];

        return match ($method) {
            'initialize' => $this->result($id, $this->initialize($room, $params)),
            'ping' => $this->result($id, (object) []),
            'tools/list' => $this->result($id, ['tools' => $this->tools()]),
            'tools/call' => $this->callTool($room, $id, $params),
            'resources/list' => $this->result($id, ['resources' => []]),
            'resources/templates/list' => $this->result($id, ['resourceTemplates' => []]),
            'prompts/list' => $this->result($id, ['prompts' => []]),
            default => $this->error($id, -32601, "Method not found: {$method}"),
        };
    }

    private function initialize(ConcordRoom $room, array $params): array
    {
        $requested = (string) ($params['protocolVersion'] ?? '');

        return [
            'protocolVersion' => in_array($requested, self::SUPPORTED_VERSIONS, true) ? $requested : self::SUPPORTED_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => [
                'name' => 'concord-shared-memory',
                'title' => 'Concord: '.$room->title,
                'version' => '2.1.0',
            ],
            'instructions' => self::INSTRUCTIONS,
        ];
    }

    private function tools(): array
    {
        $string = ['type' => 'string'];

        return [
            [
                'name' => 'get_shared_memory',
                'description' => 'Read the room\'s shared memory bank, active file claims, connected agents, recent group chat messages and your queued tasks.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                'annotations' => ['readOnlyHint' => true],
            ],
            [
                'name' => 'write_shared_memory',
                'description' => 'Store or replace a shared memory entry (decision, API contract, file ownership, task result) so every connected agent sees it.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'key' => $string + ['description' => 'Stable identifier, e.g. auth_api_contract. Writing an existing key replaces it.'],
                        'category' => $string + ['description' => 'decision, contract, ownership, result or context'],
                        'content' => $string + ['description' => 'One or two sentences.'],
                    ],
                    'required' => ['key', 'content'],
                ],
            ],
            [
                'name' => 'send_group_message',
                'description' => 'Post a message to the room\'s group chat. Other agents receive it as a handoff.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'body' => $string,
                        'target_agent' => $string + ['description' => 'Participant id or name to address; omit for everyone.'],
                    ],
                    'required' => ['body'],
                ],
            ],
            [
                'name' => 'report_thinking',
                'description' => 'Update your live status in the room visualizer: what you are thinking, doing, and which local files you changed.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'prompt' => $string + ['description' => 'The request your user just gave you. Send it once when you start on a new request.'],
                        'status' => $string + ['description' => 'working or idle'],
                        'current_thought' => $string,
                        'current_action' => $string,
                        'thinking_steps' => ['type' => 'array', 'items' => $string],
                        'local_modified_files' => ['type' => 'array', 'items' => $string],
                    ],
                ],
            ],
            [
                'name' => 'pull_local_tasks',
                'description' => 'Fetch the oldest queued task addressed to you (your computer), if any.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) []],
                'annotations' => ['readOnlyHint' => true],
            ],
        ];
    }

    private function callTool(ConcordRoom $room, mixed $id, array $params): array
    {
        $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $caller = $this->caller;
        $author = $caller?->name ?? 'Remote MCP Agent';
        $authorUser = $caller?->user_name ?? 'Remote MCP User';

        switch ($params['name'] ?? '') {
            case 'get_shared_memory':
                return $this->text($id, json_encode([
                    'room' => $room->title,
                    'you' => $caller?->only(['id', 'name', 'user_name', 'device_name']),
                    'shared_memory' => $room->shared_memory ?? [],
                    'active_claims' => $room->active_claims ?? [],
                    'participants' => $room->participants()->get(['id', 'user_name', 'device_name', 'name', 'model', 'status', 'current_thought']),
                    'recent_messages' => $room->messages()->latest('id')->limit(10)->get(['sender_name', 'sender_device', 'kind', 'content']),
                    'queued_tasks_for_you' => $caller
                        ? $room->localTasks()->where('status', 'queued')->where('target_participant_id', $caller->id)->count()
                        : 0,
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            case 'write_shared_memory':
                $content = trim((string) ($args['content'] ?? $args['value'] ?? ''));
                if ($content === '') {
                    return $this->text($id, 'content is required.', isError: true);
                }

                $record = $this->hub->upsertMemory($room, [
                    'key' => trim((string) ($args['key'] ?? '')) ?: 'mcp_note',
                    'category' => $args['category'] ?? 'decision',
                    'content' => $content,
                    'author_agent' => $author,
                    'author_user' => $authorUser,
                ]);

                return $this->text($id, "Saved shared memory key \"{$record['key']}\".");

            case 'send_group_message':
                $body = trim((string) ($args['body'] ?? $args['content'] ?? ''));
                if ($body === '') {
                    return $this->text($id, 'body is required.', isError: true);
                }

                $this->hub->sendPrompt($room, [
                    'sender_name' => $caller?->user_name ?? ($args['sender_name'] ?? 'Remote MCP Agent'),
                    'sender_device' => $caller?->device_name ?? ($args['sender_device'] ?? 'Local computer via MCP'),
                    'participant_id' => $caller?->id,
                    'target_agent' => $args['target_agent'] ?? 'all',
                    'content' => $body,
                ]);

                return $this->text($id, 'Posted to the group chat.');

            case 'report_thinking':
                $participant = ! empty($args['participant_id'])
                    ? $room->participants()->whereKey($args['participant_id'])->first()
                    : $caller;

                if (! $participant) {
                    return $this->text($id, 'Unknown caller: add user_email, user_name and device_name to the MCP URL query string.', isError: true);
                }

                $participant->update(array_filter([
                    'current_thought' => $args['current_thought'] ?? null,
                    'thinking_steps' => $args['thinking_steps'] ?? null,
                    'local_modified_files' => $args['local_modified_files'] ?? null,
                ], fn ($value) => $value !== null));

                $idle = in_array($args['status'] ?? '', ['idle', 'done'], true);
                $participant->recordActivity(
                    $idle ? 'done' : (isset($args['prompt']) ? 'prompt' : 'status'),
                    (string) ($args['current_action'] ?? ($idle ? 'Finished' : (isset($args['prompt']) ? 'Started a new request' : ''))),
                    isset($args['prompt']) ? (string) $args['prompt'] : null,
                );

                return $this->text($id, 'Status updated.');

            case 'pull_local_tasks':
                $task = $room->localTasks()
                    ->where('status', 'queued')
                    ->when($caller, fn ($q) => $q->where('target_participant_id', $caller->id))
                    ->when(! $caller && ! empty($args['participant_id']), fn ($q) => $q->where('target_participant_id', $args['participant_id']))
                    ->when(! $caller && ! empty($args['device_name']), fn ($q) => $q->where('target_device', $args['device_name']))
                    ->oldest('created_at')
                    ->first();

                return $this->text($id, $task ? json_encode($task, JSON_UNESCAPED_SLASHES) : 'No queued tasks.');
        }

        return $this->error($id, -32602, 'Unknown tool: '.($params['name'] ?? ''));
    }

    /**
     * Lifecycle events from agent hooks (Claude Code posts its hook JSON here unchanged)
     * or any client sending {"event": "prompt|tool|done", "prompt": "...", "text": "..."}.
     */
    public function handleEvent(Request $request, ConcordRoom $room): JsonResponse
    {
        $caller = $this->identifyCaller($request, $room);
        if (! $caller) {
            return $this->json(['ok' => false, 'message' => 'Add user_email or user_name to the URL query string.'], 422);
        }

        $hookEvent = (string) $request->input('hook_event_name', '');
        $event = (string) $request->input('event', match ($hookEvent) {
            'UserPromptSubmit' => 'prompt',
            'PreToolUse' => 'tool',
            'Stop', 'SubagentStop', 'SessionEnd' => 'done',
            default => 'status',
        });

        switch ($event) {
            case 'prompt':
                $caller->recordActivity('prompt', 'Started a new request', (string) $request->input('prompt', ''));
                break;

            case 'tool':
                $toolName = (string) $request->input('tool_name', 'Tool');
                $input = $request->input('tool_input');
                $caller->recordActivity('tool', (string) ($request->input('text') ?: $this->describeTool($toolName, is_array($input) ? $input : [], (string) $request->input('cwd', ''))));

                $file = is_array($input) ? ($input['file_path'] ?? $input['notebook_path'] ?? null) : null;
                if ($file && in_array($toolName, ['Edit', 'MultiEdit', 'Write', 'NotebookEdit'], true)) {
                    $files = array_values(array_unique([...($caller->local_modified_files ?? []), $this->relativePath((string) $file, (string) $request->input('cwd', ''))]));
                    $caller->update(['local_modified_files' => array_slice($files, -20)]);
                }
                break;

            case 'done':
                $caller->recordActivity('done', (string) ($request->input('text') ?: 'Finished'));
                break;

            default:
                $caller->recordActivity('status', (string) $request->input('text', ''));
        }

        return $this->json(['ok' => true]);
    }

    /**
     * One readable line per tool call. Never stores file contents or edit bodies.
     */
    private function describeTool(string $tool, array $input, string $cwd): string
    {
        $path = fn (string $key) => $this->relativePath((string) ($input[$key] ?? ''), $cwd);
        $clip = fn (string $value) => mb_strimwidth(preg_replace('/\s+/', ' ', $value), 0, 140, '…');

        return match (true) {
            in_array($tool, ['Read', 'Edit', 'MultiEdit', 'Write'], true) => "{$tool} {$path('file_path')}",
            $tool === 'NotebookEdit' => "Edit notebook {$path('notebook_path')}",
            $tool === 'Bash' => isset($input['description']) ? $clip((string) $input['description']) : 'Run '.$clip((string) ($input['command'] ?? '')),
            in_array($tool, ['Grep', 'Glob'], true) => "Search {$clip((string) ($input['pattern'] ?? ''))}",
            $tool === 'WebFetch' => "Fetch {$clip((string) ($input['url'] ?? ''))}",
            $tool === 'WebSearch' => "Web search {$clip((string) ($input['query'] ?? ''))}",
            in_array($tool, ['Task', 'Agent'], true) => 'Subagent: '.$clip((string) ($input['description'] ?? '')),
            $tool === 'TodoWrite' => 'Update todo list',
            str_starts_with($tool, 'mcp__') => 'MCP '.str_replace('__', ' / ', substr($tool, 5)),
            default => $tool,
        };
    }

    private function relativePath(string $path, string $cwd): string
    {
        if ($cwd !== '' && str_starts_with($path, rtrim($cwd, '/').'/')) {
            return substr($path, strlen(rtrim($cwd, '/')) + 1);
        }

        return $path;
    }

    /**
     * The MCP URL carries ?user_email=&user_name=&device_name= so each connected
     * agent shows up (and acts) as its own participant in the room.
     */
    private function identifyCaller(Request $request, ConcordRoom $room): ?ConcordParticipant
    {
        $params = $request->json('params');
        $params = is_array($params) ? $params : [];
        $email = strtolower(trim((string) ($request->query('user_email') ?? ($params['user_email'] ?? ''))));
        $user = trim((string) ($request->query('user_name') ?? ($params['user_name'] ?? '')));
        $device = trim((string) ($request->query('device_name') ?? ($params['device_name'] ?? '')));

        if ($email === '' && $user === '') {
            return null;
        }

        $userName = $user ?: explode('@', $email)[0];
        $existing = $room->participants()
            ->when($email !== '', fn ($q) => $q->whereRaw('LOWER(account_email) = ?', [$email]))
            ->when($email === '', fn ($q) => $q->whereRaw('LOWER(user_name) = ?', [strtolower($user)]))
            ->when($device !== '', fn ($q) => $q->orderByRaw('CASE WHEN device_name = ? THEN 0 ELSE 1 END', [$device]))
            ->first();

        if ($existing) {
            $existing->update([
                'execution_mode' => 'mcp_connected',
                'device_name' => $device ?: $existing->device_name,
                'last_seen_at' => now(),
            ]);

            return $existing;
        }

        $clientName = (string) data_get($request->json('params'), 'clientInfo.name', '');
        $provider = (string) ($params['provider'] ?? ($clientName !== '' ? $clientName : 'mcp'));
        $resolvedDevice = $device ?: $userName.'-MCP-Workstation';
        $colors = ['#10b981', '#6366f1', '#f59e0b', '#ec4899', '#06b6d4', '#8b5cf6'];

        return $room->participants()->create([
            'kind' => 'agent',
            'name' => $userName.' MCP Agent',
            'user_name' => $userName,
            'account_email' => $email ?: strtolower(preg_replace('/[^a-zA-Z0-9]+/', '.', $userName)).'@local.mcp',
            'device_name' => $resolvedDevice,
            'execution_mode' => 'mcp_connected',
            'provider' => substr($provider, 0, 40),
            'model' => (string) ($params['model'] ?? 'local-agent'),
            'avatar' => 'MCP',
            'color' => $colors[$room->participants()->count() % count($colors)],
            'role_prompt' => 'Local MCP Workspace Agent',
            'status' => 'idle',
            'current_thought' => 'Connected over MCP from '.$resolvedDevice,
            'current_action' => 'MCP connected ('.$resolvedDevice.')',
            'thinking_steps' => ['MCP handshake from '.$resolvedDevice],
            'claimed_files' => [],
            'local_modified_files' => [],
            'last_seen_at' => now(),
        ]);
    }

    private function text(mixed $id, string $text, bool $isError = false): array
    {
        return $this->result($id, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError]);
    }

    private function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    private function json(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, [], JSON_UNESCAPED_SLASHES);
    }
}
