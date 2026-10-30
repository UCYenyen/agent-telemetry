<?php

namespace App\Services;

use App\Jobs\ReplyViaOpenRouter;
use App\Models\AgentSession;
use App\Models\Setting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;

class AgentSessionManager
{
    private const CLAUDE_LIMITS = [
        'usage limit reached',
        'rate limit',
        'weekly limit',
        'session limit',
        'please try again at',
        'out of extra usage',
        'credit balance too low',
        '429 Too Many Requests',
        'overloaded with requests',
        'quota exceeded',
    ];

    private const ANTIGRAVITY_LIMITS = [
        'quota exceeded',
        'resource exhausted',
        'rate limit',
        '429 Too Many Requests',
        'daily limit',
        'token limit exceeded',
    ];

    private const WORKING = '/Thinking…|Generating…|Running command|Executing tool|Searching|[⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏]/u';

    private const DIALOG = '/\[y\/n\]|\(y\/N\)|Do you want to proceed\?|Press Enter to continue|Allow .* to execute/';

    private const REMOTE_URL = '/https:\/\/(claude\.ai\/code\/[^\s\x1b]+|antigravity\.google(?:\.com)?\/[^\s\x1b]+)/';

    public function __construct(private Tmux $tmux, private OpenRouter $openRouter) {}

    public function start(string $title, string $agent, ?string $model, string $workdir, bool $useOpenRouter): AgentSession
    {
        $defaults = Setting::read('defaults');
        $name = 'Agent_'.Str::lower(Str::random(8));
        $useOpenRouter = $useOpenRouter || $agent === 'openrouter';

        [$agent, $model] = match (true) {
            $useOpenRouter => ['openrouter', $model ?: $defaults['openrouter_model']],
            $agent === 'antigravity' => ['antigravity', $model ?: $defaults['antigravity_model']],
            default => ['claude', $model ?: $defaults['claude_model']],
        };

        if (! $useOpenRouter && ! $this->tmux->create($name, $this->command($agent, $model, $title), $workdir)) {
            Log::warning("[session] tmux session creation failed for {$name}");
        }

        return AgentSession::create([
            'name' => $name,
            'title' => $title,
            'agent' => $agent,
            'model' => $model,
            'use_openrouter' => $useOpenRouter,
            'fallback_enabled' => (bool) $defaults['fallback_enabled'],
        ]);
    }

    public function prompt(AgentSession $session, string $text): bool
    {
        $session->update(['last_prompt' => $text]);
        $session->messages()->create(['role' => 'user', 'content' => $text, 'model' => $session->model]);

        if ($session->use_openrouter) {
            $this->replyViaOpenRouter($session, $text);

            return true;
        }

        if ($this->tmux->send($session->name, $text)) {
            return true;
        }

        if ($session->fallback_enabled && Setting::read('defaults')['openrouter_fallback_enabled'] && $this->openRouter->keyFor() !== '') {
            $this->triggerOpenRouterFallback($session, 'PTY/Tmux unreachable — cascaded to OpenRouter API');

            return true;
        }

        return false;
    }

    public function switchModel(AgentSession $session, string $model): void
    {
        $session->model = $model;
        $lower = Str::lower($model);

        if (str_contains($model, '/')) {
            $session->fill(['agent' => 'openrouter', 'use_openrouter' => true]);
        } elseif (str_contains($lower, 'gemini')) {
            $session->fill(['agent' => 'antigravity', 'use_openrouter' => false]);
        } elseif (str_contains($lower, 'claude')) {
            $session->fill(['agent' => 'claude', 'use_openrouter' => false]);
        }

        $session->save();

        if ($session->agent === 'claude') {
            [$cliModel, $effort] = $this->splitClaudeModel($model);
            $this->tmux->send($session->name, "/model {$cliModel}");

            if ($effort !== null) {
                $this->tmux->send($session->name, "/effort {$effort}");
            }
        } elseif (! $session->use_openrouter) {
            $this->tmux->send($session->name, "/model {$model}");
        }
    }

    public function toggleOpenRouter(AgentSession $session, bool $enabled): void
    {
        $defaults = Setting::read('defaults');
        $isOpenRouterModel = str_contains($session->model, '/');

        $session->update($enabled
            ? ['use_openrouter' => true, 'agent' => 'openrouter', 'model' => $isOpenRouterModel ? $session->model : $defaults['openrouter_model']]
            : ['use_openrouter' => false, 'agent' => 'claude', 'model' => $isOpenRouterModel ? $defaults['claude_model'] : $session->model]);
    }

    public function stop(AgentSession $session): void
    {
        $this->tmux->kill($session->name);
        $session->delete();
    }

    public function history(AgentSession $session): array
    {
        $terminalOutput = $session->use_openrouter ? '' : $this->tmux->capture($session->name);

        return [
            'session' => $session,
            'messages' => $session->messages()->oldest('id')->get(),
            'terminal_output' => $terminalOutput,
            'live_telemetry' => $this->parseLiveTelemetry($terminalOutput, $session),
        ];
    }

    public function parseLiveTelemetry(string $pane, AgentSession $session): array
    {
        if ($session->use_openrouter || trim($pane) === '') {
            return [
                'is_thinking' => $session->state === 'working',
                'current_thought' => $session->state === 'working'
                    ? "Synthesizing response via OpenRouter ({$session->model})…"
                    : 'Ready for instructions',
                'current_action' => $session->state === 'working' ? 'Streaming API completion' : 'Idle',
                'thinking_steps' => [],
                'tool_calls' => [],
                'clean_response' => '',
            ];
        }

        $lines = explode("\n", str_replace("\r", '', $pane));
        $thinkingSteps = [];
        $toolCalls = [];
        $responseLines = [];
        $currentThought = null;
        $currentAction = null;

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);

            if ($line === '') {
                continue;
            }

            // Skip TUI chrome, dividers, ASCII banners, and status footers
            if (
                preg_match('/^[─━═┄┈\-_]{6,}$/u', $line) ||
                preg_match('/^[▄▀█▌▐░▒▓\s]+$/u', $line) ||
                str_contains($line, 'auto mode on (shift+tab') ||
                str_contains($line, 'Accept-edits mode:') ||
                str_contains($line, '? for shortcuts') ||
                str_contains($line, 'Esc to cancel') ||
                str_contains($line, 'Remote control on for this session') ||
                str_contains($line, 'another device to take over') ||
                str_starts_with($line, 'root@') ||
                str_starts_with($line, 'claude --permission-mode') ||
                str_starts_with($line, 'agy --mode')
            ) {
                continue;
            }

            // Detect thinking / reasoning lines (Claude Code ✻ / ∴ or Antigravity Thinking)
            if (preg_match('/^(?:[✻✺✹✸✷✶✦✧∴🌀⠋⠙⠹⠸⠼⠴⠦⠧⠇⠏]\s*)?(Thinking[^:]*|Thought for [^\n]+|Reasoning[^:]*|Analyzing[^:]*|Planning[^:]*|Synthesizing[^:]*)(.*)$/ui', $line, $m)) {
                $thoughtText = trim($m[1].' '.$m[2]);
                $thinkingSteps[] = $thoughtText;
                $currentThought = $thoughtText;

                continue;
            }

            // Detect tool invocations (⏺ Read(...), ⏺ Bash(...), ⏺ Edit(...), Running command, Executing tool)
            if (preg_match('/^(?:[⏺●◆▶⚡]\s*)?(Read|Edit|Write|Bash|Search|Grep|Glob|Fetch|Task|Update|Inspect|Claim|MCP|Running command|Executing tool|Viewing|Searching)\s*[\(:]?\s*(.+?)\)?$/ui', $line, $m)) {
                $toolName = ucfirst(strtolower(trim($m[1])));
                $toolTarget = trim($m[2]);
                $toolCalls[] = [
                    'tool' => $toolName,
                    'target' => $toolTarget,
                    'output' => null,
                ];
                $currentAction = "{$toolName}: {$toolTarget}";

                continue;
            }

            // Detect tool output sub-lines (⎿  ...)
            if (preg_match('/^⎿\s*(.+)$/u', $line, $m)) {
                $subText = trim($m[1]);
                if (! empty($toolCalls)) {
                    $lastIdx = count($toolCalls) - 1;
                    $toolCalls[$lastIdx]['output'] = $toolCalls[$lastIdx]['output']
                        ? $toolCalls[$lastIdx]['output']."\n".$subText
                        : $subText;
                } else {
                    $thinkingSteps[] = $subText;
                }

                continue;
            }

            // Skip echoed prompt lines starting with ❯ or >
            if (preg_match('/^[❯>]\s*(.*)$/u', $line)) {
                continue;
            }

            $responseLines[] = $rawLine;
        }

        $isWorking = $session->state === 'working' || (bool) preg_match(self::WORKING, $pane);

        if (! $currentThought) {
            $currentThought = $isWorking
                ? 'Evaluating context window and formulating next step…'
                : (! empty($thinkingSteps) ? end($thinkingSteps) : 'Context synchronized — waiting for prompt');
        }

        if (! $currentAction) {
            $currentAction = ! empty($toolCalls)
                ? end($toolCalls)['tool'].': '.end($toolCalls)['target']
                : ($isWorking ? 'Generating response…' : 'Idle · Ready');
        }

        return [
            'is_thinking' => $isWorking,
            'current_thought' => $currentThought,
            'current_action' => $currentAction,
            'thinking_steps' => array_slice(array_values(array_unique($thinkingSteps)), -12),
            'tool_calls' => array_slice($toolCalls, -12),
            'clean_response' => trim(implode("\n", array_slice($responseLines, -30))),
        ];
    }

    public function syncDiscovered(): void
    {
        $processes = $this->processes();
        $children = [];

        foreach ($processes as $pid => $process) {
            $children[$process['ppid']][] = $pid;
        }

        $known = AgentSession::withTrashed()->pluck('name')->all();
        $inTmux = [];

        // Identify each tmux session by the agent process running in its pane, not by its screen text
        foreach ($this->tmux->panePids() as $name => $panePid) {
            $pids = $this->descendants($children, $panePid);
            $inTmux = [...$inTmux, ...$pids];

            if (in_array($name, $known, true)) {
                continue;
            }

            $agent = collect($pids)->map(fn (int $pid) => $this->parseProcess($processes[$pid]['args'] ?? ''))->filter()->first();

            AgentSession::create([
                'name' => $name,
                'title' => $agent['title'] ?? $name,
                'agent' => $agent['agent'] ?? 'claude',
                'model' => $agent['model'] ?? 'auto',
            ]);
        }

        $this->syncProcesses($processes, array_flip($inTmux));
    }

    public function updateStates(): void
    {
        $openRouterFallback = Setting::read('defaults')['openrouter_fallback_enabled'];

        foreach (AgentSession::where('use_openrouter', false)->get() as $session) {
            if (! $this->tmux->has($session->name)) {
                $session->update(['state' => 'idle']);

                continue;
            }

            $pane = $this->tmux->capture($session->name, 100);

            if ($pane === '') {
                continue;
            }

            if (preg_match(self::REMOTE_URL, $pane, $match)) {
                $session->remote_url = $match[0];
            }

            if ($session->agent === 'claude' && $session->fallback_enabled && $session->fallback_stage === 0 && ($reason = $this->firstMatch(self::CLAUDE_LIMITS, $pane))) {
                $this->triggerAntigravityFallback($session, $reason);
            } elseif ($session->agent === 'antigravity' && $session->fallback_enabled && $session->fallback_stage <= 1 && $openRouterFallback && ($reason = $this->firstMatch(self::ANTIGRAVITY_LIMITS, $pane))) {
                $this->triggerOpenRouterFallback($session, $reason);
            }

            $session->state = match (true) {
                $session->fallback_triggered => 'fallback_active',
                (bool) preg_match(self::WORKING, $pane) => 'working',
                (bool) preg_match(self::DIALOG, $pane) => 'dialog',
                default => 'idle',
            };

            $session->save();
        }
    }

    private function command(string $agent, string $model, string $title): string
    {
        if ($agent === 'antigravity') {
            return sprintf('%s --mode accept-edits --model %s --remote-control', config('agenthost.agy_bin'), escapeshellarg($model));
        }

        $defaults = Setting::read('defaults');
        [$cliModel, $effort] = $this->splitClaudeModel($model);

        return sprintf(
            'claude --permission-mode %s --model %s --effort %s --autocompact auto -n %s --remote-control %s',
            escapeshellarg($defaults['permission_mode']),
            escapeshellarg($cliModel),
            escapeshellarg($effort ?? $defaults['claude_effort']),
            escapeshellarg($title),
            escapeshellarg($title),
        );
    }

    /**
     * The picker lists Claude models as "<id>-<effort>", but the CLI only knows the bare id
     * and takes the effort as its own flag.
     */
    private function splitClaudeModel(string $model): array
    {
        return preg_match('/^(claude-.+)-(low|medium|high|xhigh|max)$/', $model, $m) ? [$m[1], $m[2]] : [$model, null];
    }

    private function replyViaOpenRouter(AgentSession $session, string $text): void
    {
        $session->update(['state' => 'working']);

        ReplyViaOpenRouter::dispatch($session, $text);
    }

    private function triggerAntigravityFallback(AgentSession $session, string $reason): void
    {
        Log::info("[fallback-stage-1] Triggering Antigravity fallback on '{$session->title}' due to: {$reason}");

        $model = Setting::read('defaults')['antigravity_model'];
        $session->fill(['fallback_triggered' => true, 'fallback_stage' => 1, 'agent' => 'antigravity', 'model' => $model])->save();

        $this->tmux->send($session->name, "\x03", false);
        Sleep::for(500)->milliseconds();
        $this->tmux->send($session->name, '/exit');
        Sleep::for(500)->milliseconds();
        $this->tmux->send($session->name, $this->command('antigravity', $model, $session->title));
        Sleep::for(1)->second();

        if ($session->last_prompt) {
            $this->tmux->send($session->name, "[Failover Stage 1: Claude limit reached -> Switched to Antigravity ({$model})] {$session->last_prompt}");
        }
    }

    private function triggerOpenRouterFallback(AgentSession $session, string $reason): void
    {
        Log::info("[fallback-stage-2] Triggering OpenRouter fallback on '{$session->title}' due to: {$reason}");

        $model = Setting::read('defaults')['openrouter_model'];
        $session->fill(['fallback_triggered' => true, 'fallback_stage' => 2, 'use_openrouter' => true, 'agent' => 'openrouter', 'model' => $model])->save();

        $session->messages()->create([
            'role' => 'assistant',
            'content' => "⚡ [Cascading Failover Stage 2 Activated ({$reason}): Switched to OpenRouter API ({$model})]",
            'model' => "openrouter // {$model}",
        ]);

        if ($session->last_prompt) {
            $this->replyViaOpenRouter($session, $session->last_prompt);
        }
    }

    /**
     * Agent CLIs started outside tmux. Processes inside a tmux session are already tracked by that session.
     */
    private function syncProcesses(array $processes, array $inTmux): void
    {
        if ($processes === []) {
            return;
        }

        $live = [];

        foreach ($processes as $pid => ['args' => $args]) {
            $process = isset($inTmux[$pid]) ? null : $this->parseProcess($args);

            if (! $process) {
                continue;
            }

            $name = "proc_{$pid}";
            $title = $process['title'] ?? "Antigravity CLI ({$pid})";
            $live[] = $name;

            if (AgentSession::where('name', $name)->orWhere('title', $title)->exists()) {
                continue;
            }

            AgentSession::create(['name' => $name, 'title' => $title, 'agent' => $process['agent'], 'model' => $process['model']]);
        }

        AgentSession::whereNotIn('name', $live)
            ->get()
            ->filter(fn (AgentSession $session) => str_starts_with($session->name, 'proc_'))
            ->each->forceDelete();
    }

    /**
     * pid => ['ppid' => int, 'args' => string] for every process on the host.
     */
    private function processes(): array
    {
        $result = Process::run(['ps', '-eo', 'pid=,ppid=,args=']);

        if ($result->failed()) {
            return [];
        }

        $processes = [];

        foreach (explode("\n", trim($result->output())) as $line) {
            $parts = preg_split('/\s+/', trim($line), 3);

            if (count($parts) === 3 && ctype_digit($parts[0])) {
                $processes[(int) $parts[0]] = ['ppid' => (int) $parts[1], 'args' => $parts[2]];
            }
        }

        return $processes;
    }

    private function descendants(array $children, int $root): array
    {
        $pids = [$root];

        for ($i = 0; $i < count($pids); $i++) {
            array_push($pids, ...($children[$pids[$i]] ?? []));
        }

        return $pids;
    }

    private function parseProcess(string $args): ?array
    {
        if (str_contains($args, 'claude ') && str_contains($args, '--permission-mode')) {
            $parts = preg_split('/\s+/', $args);
            $nameAt = array_search('-n', $parts, true);
            $remoteAt = array_search('--remote-control', $parts, true);
            $modelAt = array_search('--model', $parts, true);

            if ($nameAt === false || $remoteAt === false) {
                return null;
            }

            return [
                'title' => implode(' ', array_slice($parts, $nameAt + 1, $remoteAt - $nameAt - 1)),
                'agent' => 'claude',
                'model' => $modelAt === false ? 'claude-opus-5-5-medium' : ($parts[$modelAt + 1] ?? 'claude-opus-5-5-medium'),
            ];
        }

        $isAntigravity = str_contains($args, 'agy') || str_contains($args, 'antigravity');
        $isInteractive = str_contains($args, '--mode') || str_contains($args, '--remote-control') || str_contains($args, '/snap/antigravity');

        if ($isAntigravity && $isInteractive && ! str_contains($args, '--disable-slash-commands')) {
            preg_match('/--model\s+["\']?([^"\'\s]+)/', $args, $match);

            return ['title' => null, 'agent' => 'antigravity', 'model' => $match[1] ?? 'gemini-3.8-flash-medium'];
        }

        return null;
    }

    private function firstMatch(array $patterns, string $text): ?string
    {
        return collect($patterns)->first(fn (string $pattern) => stripos($text, $pattern) !== false);
    }
}
