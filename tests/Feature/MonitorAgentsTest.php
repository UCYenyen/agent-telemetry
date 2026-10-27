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
        $this->fakeProcesses(pane: "Working\nClaude usage limit reached. https://claude.ai/code/abc123\n", ps: "  PID ARGS\n 4242 agy --mode accept-edits --model gemini-4-argon-low --remote-control\n");

        AgentSession::create(['name' => 'Agent_one', 'title' => 'One', 'agent' => 'claude', 'model' => 'claude-opus-5-5-high', 'last_prompt' => 'Continue']);

        $this->artisan('agents:monitor --once')->assertSuccessful();

        $session = AgentSession::where('name', 'Agent_one')->first();
        $this->assertSame('antigravity', $session->agent);
        $this->assertSame(1, $session->fallback_stage);
        $this->assertSame('fallback_active', $session->state);
        $this->assertSame('https://claude.ai/code/abc123', $session->remote_url);
        Process::assertRan(fn ($process) => end($process->command) === "\x03");

        $this->assertDatabaseHas('agent_sessions', ['name' => 'Agent_discovered', 'agent' => 'antigravity']);
        $this->assertDatabaseHas('agent_sessions', ['name' => 'proc_4242', 'model' => 'gemini-4-argon-low']);
    }

    public function test_dead_processes_are_forgotten(): void
    {
        $this->fakeProcesses(pane: '', ps: "  PID ARGS\n");
        AgentSession::create(['name' => 'proc_99', 'title' => 'Gone', 'agent' => 'antigravity']);

        $this->artisan('agents:monitor --once')->assertSuccessful();

        $this->assertDatabaseMissing('agent_sessions', ['name' => 'proc_99']);
    }

    private function fakeProcesses(string $pane, string $ps): void
    {
        Process::fake(fn (PendingProcess $process) => match ($process->command[0] === 'ps' ? 'ps' : $process->command[1]) {
            'ps' => Process::result($ps),
            'list-sessions' => Process::result("Agent_one\nAgent_discovered\n"),
            'capture-pane' => Process::result($process->command[3] === 'Agent_discovered' ? 'Antigravity CLI ready' : $pane),
            default => Process::result(),
        });
    }
}
