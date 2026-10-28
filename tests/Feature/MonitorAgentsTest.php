<?php

namespace Tests\Feature;

use App\Models\AgentSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class MonitorAgentsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_claude_limit_triggers_antigravity_failover(): void
    {
        Sleep::fake();
        $this->fakeProcesses(
            pane: "Working\nClaude usage limit reached. https://claude.ai/code/abc123\n",
            panes: "Agent_one\t100\nAgent_discovered\t200\n",
            ps: " 100 1 bash\n 200 1 bash\n 201 200 agy --mode accept-edits --model gemini-4-argon-low --remote-control\n 4242 1 agy --mode accept-edits --model gemini-4-argon-low --remote-control\n",
        );

        AgentSession::create(['name' => 'Agent_one', 'title' => 'One', 'agent' => 'claude', 'model' => 'claude-opus-5-5-high', 'last_prompt' => 'Continue']);

        $this->artisan('agents:monitor --once')->assertSuccessful();

        $session = AgentSession::where('name', 'Agent_one')->first();
        $this->assertSame('antigravity', $session->agent);
        $this->assertSame(1, $session->fallback_stage);
        $this->assertSame('fallback_active', $session->state);
        $this->assertSame('https://claude.ai/code/abc123', $session->remote_url);
        Process::assertRan(fn ($process) => end($process->command) === "\x03");

        $this->assertDatabaseHas('agent_sessions', ['name' => 'Agent_discovered', 'agent' => 'antigravity', 'model' => 'gemini-4-argon-low']);
        $this->assertDatabaseHas('agent_sessions', ['name' => 'proc_4242', 'model' => 'gemini-4-argon-low']);
        $this->assertDatabaseMissing('agent_sessions', ['name' => 'proc_201']);
    }

    public function test_tmux_session_is_identified_by_its_process_not_its_screen(): void
    {
        $this->fakeProcesses(
            pane: 'We talked about agy and the Antigravity CLI here',
            panes: "Agent_86ddcd36\t300\n",
            ps: " 300 1 bash\n 301 300 claude --permission-mode auto --model claude-opus-5-5-low --effort medium --autocompact auto -n Multiagent Host --remote-control Multiagent Host\n 302 301 node mcp-server.js\n",
        );

        $this->artisan('agents:monitor --once')->assertSuccessful();

        $this->assertDatabaseHas('agent_sessions', ['name' => 'Agent_86ddcd36', 'agent' => 'claude', 'title' => 'Multiagent Host', 'model' => 'claude-opus-5-5-low']);
        $this->assertDatabaseMissing('agent_sessions', ['name' => 'proc_301']);
        $this->assertSame(1, AgentSession::count());
    }

    public function test_dead_processes_are_forgotten(): void
    {
        $this->fakeProcesses(pane: '', panes: '', ps: " 1 0 init\n");
        AgentSession::create(['name' => 'proc_99', 'title' => 'Gone', 'agent' => 'antigravity']);

        $this->artisan('agents:monitor --once')->assertSuccessful();

        $this->assertDatabaseMissing('agent_sessions', ['name' => 'proc_99']);
    }

    private function fakeProcesses(string $pane, string $panes, string $ps): void
    {
        Process::fake(fn (PendingProcess $process) => match ($process->command[0] === 'ps' ? 'ps' : $process->command[1]) {
            'ps' => Process::result($ps),
            'list-panes' => Process::result($panes),
            'capture-pane' => Process::result($pane),
            default => Process::result(),
        });
    }
}
