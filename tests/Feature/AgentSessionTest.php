<?php

namespace Tests\Feature;

use App\Models\AgentSession;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AgentSessionTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_openrouter_session_replies_through_the_queue(): void
    {
        Setting::merge('openrouter', ['api_key' => 'sk-or-v1-abcdefghijklmnop']);
        Http::fake(['openrouter.ai/*' => Http::response(['model' => 'openai/gpt-4.1', 'choices' => [['message' => ['content' => 'Hello operator']]]])]);

        $session = $this->postJson('/api/sessions', ['title' => 'Chat', 'agent' => 'openrouter', 'model' => 'openai/gpt-4.1', 'workdir' => '/tmp'])
            ->assertCreated()
            ->json();

        $this->postJson("/api/sessions/{$session['id']}/messages", ['text' => 'Hi'])->assertCreated();

        $history = $this->getJson("/api/sessions/{$session['id']}")->assertOk();
        $history->assertJsonPath('messages.0.content', 'Hi')
            ->assertJsonPath('messages.1.content', 'Hello operator')
            ->assertJsonPath('messages.1.model', 'openrouter // openai/gpt-4.1')
            ->assertJsonPath('session.state', 'idle');

        Http::assertSent(fn ($request) => $request['messages'] === [['role' => 'user', 'content' => 'Hi']]);
    }

    public function test_cli_session_launches_tmux_with_escaped_title(): void
    {
        Process::fake(fn (PendingProcess $process) => Process::result(exitCode: $process->command[1] === 'has-session' ? 1 : 0));

        $this->postJson('/api/sessions', ['title' => 'x"; rm -rf ~; "', 'agent' => 'claude', 'workdir' => '/tmp'])->assertCreated();

        Process::assertRan(fn ($process) => in_array('-l', $process->command, true) && str_contains(end($process->command), "-n 'x\"; rm -rf ~; \"'"));
    }

    public function test_switching_to_an_openrouter_model_moves_session_to_openrouter(): void
    {
        Process::fake();
        $session = AgentSession::create(['name' => 'Agent_test', 'title' => 'T', 'agent' => 'claude', 'model' => 'claude-sonnet-5-5-medium']);

        $this->patchJson("/api/sessions/{$session->id}", ['model' => 'deepseek/deepseek-r1'])
            ->assertOk()
            ->assertJsonPath('agent', 'openrouter')
            ->assertJsonPath('use_openrouter', true);

        $this->patchJson("/api/sessions/{$session->id}", ['use_openrouter' => false])
            ->assertJsonPath('agent', 'claude')
            ->assertJsonPath('model', 'claude-sonnet-5-5-medium');

        $this->deleteJson("/api/sessions/{$session->id}")->assertNoContent();
        $this->assertSoftDeleted($session);
    }

    public function test_session_history_extracts_live_thinking_and_tool_telemetry(): void
    {
        $pane = implode("\n", [
            '────────────────────────────────────────',
            '❯ Refactor the authentication controller',
            '✻ Thinking about middleware and token validation…',
            '⏺ Read(app/Http/Controllers/LoginController.php)',
            '⎿  Read 42 lines',
            '⏺ Edit(app/Http/Controllers/LoginController.php)',
            'I have updated the authentication controller with token support.',
            '────────────────────────────────────────',
            '  ⏵⏵ auto mode on (shift+tab to cycle)',
        ]);

        Process::fake(fn () => Process::result(output: $pane));

        $session = AgentSession::create([
            'name' => 'Agent_telemetry',
            'title' => 'Auth Refactor',
            'agent' => 'claude',
            'model' => 'claude-opus-5-5-high',
        ]);

        $res = $this->getJson("/api/sessions/{$session->id}")->assertOk();
        $res->assertJsonPath('live_telemetry.is_thinking', true)
            ->assertJsonPath('live_telemetry.current_thought', 'Thinking about middleware and token validation…')
            ->assertJsonPath('live_telemetry.tool_calls.0.tool', 'Read')
            ->assertJsonPath('live_telemetry.tool_calls.0.output', 'Read 42 lines')
            ->assertJsonPath('live_telemetry.tool_calls.1.tool', 'Edit')
            ->assertJsonPath('live_telemetry.clean_response', 'I have updated the authentication controller with token support.');
    }
}
