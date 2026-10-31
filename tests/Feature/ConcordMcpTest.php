<?php

namespace Tests\Feature;

use App\Models\ConcordRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ConcordMcpTest extends TestCase
{
    use RefreshDatabase;

    private function room(): ConcordRoom
    {
        return ConcordRoom::create(['title' => 'MCP Room', 'access_mode' => 'invite_only']);
    }

    private function mcpUrl(ConcordRoom $room, string $query = 'user_email=ana@dev.io&user_name=Ana&device_name=ana-laptop'): string
    {
        return "/api/concord/mcp/{$room->mcp_token}?{$query}";
    }

    public function test_streamable_http_handshake_matches_what_mcp_clients_send(): void
    {
        $room = $this->room();
        $url = $this->mcpUrl($room);

        $this->postJson($url, [
            'jsonrpc' => '2.0',
            'id' => 0,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'claude-code', 'version' => '2.1']],
        ])->assertOk()
            ->assertJsonPath('id', 0)
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.serverInfo.name', 'concord-shared-memory')
            ->assertJsonStructure(['result' => ['capabilities' => ['tools'], 'instructions']]);

        // Notifications get 202 with no body; a 4xx here makes clients drop the connection.
        $this->postJson($url, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])
            ->assertStatus(202)
            ->assertContent('');

        $this->postJson($url, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertOk()
            ->assertExactJson(['jsonrpc' => '2.0', 'id' => 1, 'result' => []]);

        $this->postJson($url, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])
            ->assertOk()
            ->assertJsonPath('result.tools.0.name', 'get_shared_memory')
            ->assertJsonCount(5, 'result.tools');

        // Clients probing for an SSE stream or ending a session are told this server is POST-only.
        $this->get($url)->assertStatus(405)->assertHeader('Allow', 'POST');
        $this->delete($url)->assertStatus(405);

        $this->assertSame(1, $room->participants()->count());
        $this->assertSame('ana-laptop', $room->participants()->first()->device_name);
    }

    public function test_unknown_protocol_versions_fall_back_and_older_clients_are_served(): void
    {
        $room = $this->room();

        $this->postJson($this->mcpUrl($room), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2024-11-05']])
            ->assertJsonPath('result.protocolVersion', '2024-11-05');

        $this->postJson($this->mcpUrl($room), ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']])
            ->assertJsonPath('result.protocolVersion', '2025-06-18');
    }

    public function test_batches_errors_and_tool_calls_are_attributed_to_the_connected_agent(): void
    {
        Http::fake();
        $room = $this->room();
        $url = $this->mcpUrl($room);

        $this->postJson($url, [
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'resources/list'],
            ['jsonrpc' => '2.0', 'id' => 8, 'method' => 'nope/unknown'],
        ])->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.result.resources', [])
            ->assertJsonPath('1.error.code', -32601);

        $this->postJson($url, ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => [
            'name' => 'write_shared_memory',
            'arguments' => ['key' => 'auth_contract', 'category' => 'contract', 'content' => 'POST /api/login returns a bearer token.'],
        ]])->assertOk()
            ->assertJsonPath('result.isError', false);

        $memory = collect($room->fresh()->shared_memory)->firstWhere('key', 'auth_contract');
        $this->assertSame('Ana MCP Agent', $memory['author_agent']);
        $this->assertSame('Ana', $memory['author_user']);

        $this->postJson($url, ['jsonrpc' => '2.0', 'id' => 10, 'method' => 'tools/call', 'params' => [
            'name' => 'report_thinking',
            'arguments' => ['current_thought' => 'Wiring the login form'],
        ]])->assertJsonPath('result.isError', false);
        $this->assertSame('Wiring the login form', $room->participants()->first()->current_thought);

        $this->postJson($url, ['jsonrpc' => '2.0', 'id' => 11, 'method' => 'tools/call', 'params' => ['name' => 'get_shared_memory']])
            ->assertJsonPath('result.isError', false)
            ->assertSee('auth_contract');

        $this->postJson($url, ['jsonrpc' => '2.0', 'id' => 12, 'method' => 'tools/call', 'params' => ['name' => 'write_shared_memory', 'arguments' => ['key' => 'x']]])
            ->assertJsonPath('result.isError', true);

        $this->postJson($url, ['jsonrpc' => '2.0', 'id' => 13, 'method' => 'tools/call', 'params' => ['name' => 'drop_tables']])
            ->assertJsonPath('error.code', -32602);
    }

    public function test_invalid_token_and_malformed_bodies_return_json_rpc_errors(): void
    {
        $this->postJson('/api/concord/mcp/mcp_missing', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])
            ->assertNotFound()
            ->assertJsonPath('error.code', -32001);

        $room = $this->room();
        $this->call('POST', $this->mcpUrl($room), [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json')
            ->assertStatus(400)
            ->assertJsonPath('error.code', -32700);
    }

    public function test_connector_script_never_expands_values_from_the_link(): void
    {
        $room = $this->room();
        $payload = '$(touch /tmp/pwned)`id`';

        $script = $this->get('/connect/'.$room->share_token.'?user='.urlencode($payload).'&email='.urlencode("x'; id; '"))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString("DEFAULT_USER='\$(touch /tmp/pwned)`id`'", $script);
        $this->assertStringContainsString("DEFAULT_EMAIL='x'\\''; id; '\\'''", $script);
        $this->assertStringContainsString('".mcp.json": {"type": "http", "url": url}', $script);
        $this->assertStringContainsString('".gemini/settings.json": {"httpUrl": url}', $script);
        $this->assertStringContainsString('".agents/mcp_config.json": {"serverUrl": url}', $script);
        $this->assertStringContainsString('MCP_URL=$(python3', $script);
    }

    public function test_claude_code_hook_events_show_prompt_activity_and_finish(): void
    {
        $room = $this->room();
        $events = "/api/concord/mcp/{$room->mcp_token}/events?user_email=ana@dev.io&user_name=Ana&device_name=ana-laptop";

        // Claude Code posts its hook JSON unchanged.
        $this->postJson($events, ['hook_event_name' => 'UserPromptSubmit', 'cwd' => '/home/ana/app', 'prompt' => 'Add rate limiting to the login route'])
            ->assertOk();

        $agent = $room->participants()->firstOrFail();
        $this->assertSame('Add rate limiting to the login route', $agent->current_prompt);
        $this->assertSame('working', $agent->status);

        $this->postJson($events, ['hook_event_name' => 'PreToolUse', 'cwd' => '/home/ana/app', 'tool_name' => 'Edit', 'tool_input' => ['file_path' => '/home/ana/app/routes/web.php', 'old_string' => 'secret', 'new_string' => 'secret2']])
            ->assertOk();
        $this->postJson($events, ['hook_event_name' => 'PreToolUse', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'php artisan test', 'description' => 'Run the test suite']])
            ->assertOk();

        $agent->refresh();
        $this->assertSame('Run the test suite', $agent->current_action);
        $this->assertSame(['routes/web.php'], $agent->local_modified_files);
        $this->assertSame(['prompt', 'tool', 'tool'], array_column($agent->activity, 'type'));
        $this->assertSame('Edit routes/web.php', $agent->activity[1]['text']);
        $this->assertStringNotContainsString('secret', json_encode($agent->activity));

        $this->postJson($events, ['hook_event_name' => 'Stop'])->assertOk();
        $agent->refresh();
        $this->assertSame('idle', $agent->status);
        $this->assertSame('Add rate limiting to the login route', $agent->current_prompt);

        $this->postJson("/api/concord/mcp/{$room->mcp_token}/events", ['event' => 'prompt', 'prompt' => 'x'])->assertStatus(422);
    }

    public function test_report_thinking_prompt_from_other_clients_reaches_the_board(): void
    {
        $room = $this->room();

        $this->postJson($this->mcpUrl($room), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => [
            'name' => 'report_thinking',
            'arguments' => ['prompt' => 'Write the signup form', 'current_action' => 'Reading the auth controller'],
        ]])->assertJsonPath('result.isError', false);

        $agent = $room->participants()->firstOrFail();
        $this->assertSame('Write the signup form', $agent->current_prompt);
        $this->assertSame('Reading the auth controller', $agent->current_action);
        $this->assertSame('working', $agent->status);
    }

    public function test_messages_reach_real_agents_once_and_agents_never_message_themselves(): void
    {
        $room = $this->room();
        $ana = $this->mcpUrl($room);
        $ben = $this->mcpUrl($room, 'user_email=ben@dev.io&user_name=Ben&device_name=ben-pc');
        $this->postJson($ana, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $this->postJson($ben, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        $this->postJson($ana, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
            'name' => 'send_group_message',
            'arguments' => ['body' => 'I own routes/web.php, take the React side.'],
        ]])->assertJsonPath('result.isError', false);

        $this->postJson($ana, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'pull_local_tasks']])
            ->assertSee('No new messages.');

        $this->postJson($ben, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'pull_local_tasks']])
            ->assertSee('I own routes/web.php')
            ->assertSee('Ana');
        $this->postJson($ben, ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'pull_local_tasks']])
            ->assertSee('No new messages.');

        $this->assertSame(1, $room->messages()->count());
        $this->assertSame('agent', $room->messages()->first()->sender_kind);
        $this->assertSame([], $room->fresh()->shared_memory ?? []);
    }

    public function test_prompts_from_the_concord_ui_run_in_the_owners_local_claude_and_reply_back(): void
    {
        $room = $this->room();
        $this->postJson($this->mcpUrl($room), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $this->postJson($this->mcpUrl($room, 'user_email=ben@dev.io&user_name=Ben&device_name=ben-pc'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $ana = $room->participants()->where('account_email', 'ana@dev.io')->firstOrFail();
        $ben = $room->participants()->where('account_email', 'ben@dev.io')->firstOrFail();
        $hook = fn (string $who) => "/api/concord/mcp/{$room->mcp_token}/events?user_email={$who}@dev.io";

        // Ana, signed in to Agent Host, prompts her own agent and messages Ben's.
        $this->withSession(['agent_host.authenticated' => true, 'agent_host.user' => ['name' => 'Ana', 'email' => 'ana@dev.io', 'role' => 'admin']]);
        foreach ([$ana, $ben] as $target) {
            $this->postJson("/api/concord/shared/{$room->share_token}/messages", [
                'sender_email' => 'ana@dev.io',
                'target_participant_id' => $target->id,
                'body' => "Add a /health route for {$target->user_name}",
            ])->assertOk();
        }

        // Ana's Claude Code finishes its current turn: the Stop hook hands it the prompt.
        $this->postJson($hook('ana'), ['hook_event_name' => 'Stop', 'session_id' => 's1'])
            ->assertOk()
            ->assertJsonPath('decision', 'block')
            ->assertSee('Add a /health route for Ana');
        $this->assertSame('Add a /health route for Ana', $ana->fresh()->current_prompt);

        // When that turn ends, its final answer is posted back to the room and nothing else is pending.
        $this->postJson($hook('ana'), ['hook_event_name' => 'Stop', 'session_id' => 's1', 'last_assistant_message' => 'Added GET /health returning 200.'])
            ->assertOk()
            ->assertExactJson([]);
        $task = $room->localTasks()->where('target_participant_id', $ana->id)->firstOrFail();
        $this->assertSame('completed', $task->status);
        $this->assertSame('Added GET /health returning 200.', $task->reply);
        $this->assertSame('Added GET /health returning 200.', $room->messages()->where('participant_id', $ana->id)->latest('id')->value('content'));

        // Ben's agent never runs Ana's text; it only reads it as context.
        $this->postJson($hook('ben'), ['hook_event_name' => 'Stop'])->assertExactJson([]);
        $this->postJson("/api/concord/mcp/{$room->mcp_token}/inbox?user_email=ben@dev.io")->assertJsonPath('prompts', []);
        $this->postJson($this->mcpUrl($room, 'user_email=ben@dev.io&user_name=Ben&device_name=ben-pc'), ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'pull_local_tasks']])
            ->assertSee('context only')
            ->assertSee('Add a /health route for Ben');

        // The channel bridge gets each prompt exactly once.
        $this->postJson("/api/concord/shared/{$room->share_token}/messages", ['sender_email' => 'ana@dev.io', 'target_participant_id' => $ana->id, 'body' => 'Run the tests'])->assertOk();
        $inbox = $this->postJson("/api/concord/mcp/{$room->mcp_token}/inbox?user_email=ana@dev.io")->assertOk();
        $this->assertStringStartsWith("Run the tests\n\n---\nYou are Ana's agent", $inbox->json('prompts.0.text'));
        $this->postJson("/api/concord/mcp/{$room->mcp_token}/inbox?user_email=ana@dev.io")->assertJsonPath('prompts', []);
        $this->postJson($hook('ana'), ['hook_event_name' => 'Stop'])->assertExactJson([]);
    }

    public function test_connector_installs_the_stop_hook_and_channel_bridge_without_a_headless_runner(): void
    {
        $room = $this->room();
        $script = $this->get('/connect/'.$room->share_token.'?user=Ana&email=ana@dev.io')->assertOk()->getContent();

        $this->assertStringContainsString('/concord-channel.mjs" -o .concord/channel.mjs', $script);
        $this->assertStringContainsString('data["mcpServers"]["concord-channel"]', $script);
        $this->assertStringContainsString('post.replace("-s -m 3", "-sf -m 8")', $script);
        $this->assertStringNotContainsString('while true', $script);
        $this->assertStringNotContainsString('-p "${INSTRUCTIONS}"', $script);
        $this->assertFileExists(public_path('concord-channel.mjs'));
    }

    public function test_deploying_a_session_creates_its_group_and_connects_its_claude_to_it(): void
    {
        $typed = [];
        Process::fake(function ($process) use (&$typed) {
            if (($process->command[1] ?? '') === 'send-keys' && ($process->command[4] ?? '') !== 'Enter') {
                $typed[] = $process->command[5] ?? '';
            }

            return Process::result(exitCode: ($process->command[1] ?? '') === 'has-session' ? 1 : 0);
        });
        $this->withSession(['agent_host.authenticated' => true, 'agent_host.user' => ['name' => 'Bryan', 'email' => 'bryan@dev.io', 'role' => 'admin']]);

        $session = $this->postJson('/api/sessions', ['title' => 'Concord Test', 'agent' => 'claude', 'workdir' => '/tmp'])->assertCreated();
        $room = ConcordRoom::where('agent_session_id', $session->json('id'))->firstOrFail();
        $vps = $room->participants()->firstOrFail();

        $this->assertSame('Concord Test', $room->title);
        $this->assertSame('bryan@dev.io', $room->owner_email);
        $this->assertSame($session->json('id'), $vps->agent_session_id);

        // Claude is launched with this group's MCP server and hooks, as the VPS agent.
        $launch = $typed[0];
        $this->assertStringContainsString('--mcp-config', $launch);
        $this->assertStringContainsString("/api/concord/mcp/{$room->mcp_token}?", $launch);
        $this->assertStringContainsString('device_name='.urlencode('vps-'.$session->json('name')), $launch);
        $this->assertStringContainsString('--settings', $launch);

        // An MCP call from that Claude lands on the same agent, while the owner's laptop gets its own.
        $this->postJson("/api/concord/mcp/{$room->mcp_token}?user_email=bryan@dev.io&device_name=vps-".$session->json('name'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $this->postJson("/api/concord/mcp/{$room->mcp_token}?user_email=bryan@dev.io&device_name=bryan-laptop", ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $this->assertSame(2, $room->participants()->count());
        $this->assertSame('vps-'.$session->json('name'), $vps->fresh()->device_name);

        // The owner's prompt is typed into the session straight away and shows as running.
        $typed = [];
        $this->postJson("/api/concord/shared/{$room->share_token}/messages", ['sender_email' => 'bryan@dev.io', 'target_participant_id' => $vps->id, 'body' => 'Summarise the repo'])->assertOk();
        $this->assertSame(['Summarise the repo'], $typed);
        $this->assertSame('running', $room->localTasks()->where('target_participant_id', $vps->id)->value('status'));

        // Deleting the session deletes its group.
        $this->deleteJson('/api/sessions/'.$session->json('id'))->assertNoContent();
        $this->assertNull(ConcordRoom::find($room->id));
    }

    public function test_deleting_a_session_group_stops_its_session(): void
    {
        Process::fake();
        $this->withSession(['agent_host.authenticated' => true, 'agent_host.user' => ['name' => 'Bryan', 'email' => 'bryan@dev.io', 'role' => 'admin']]);
        $session = $this->postJson('/api/sessions', ['title' => 'Throwaway', 'agent' => 'claude', 'workdir' => '/tmp'])->assertCreated();
        $room = ConcordRoom::where('agent_session_id', $session->json('id'))->firstOrFail();

        $this->deleteJson("/api/concord/rooms/{$room->id}")->assertNoContent();

        $this->assertNull(ConcordRoom::find($room->id));
        $this->assertSoftDeleted('agent_sessions', ['id' => $session->json('id')]);
        Process::assertRan(fn ($process) => ($process->command[1] ?? '') === 'kill-session');
    }

    public function test_two_people_split_one_request_and_each_agent_works_on_its_own_computer(): void
    {
        $room = $this->room();
        $mcp = fn (string $who, string $device) => "/api/concord/mcp/{$room->mcp_token}?user_email={$who}@dev.io&user_name=".ucfirst($who)."&device_name={$device}";
        $hook = fn (string $who, string $device) => str_replace('?', '/events?', $mcp($who, $device));
        $this->postJson($mcp('ana', 'ana-laptop'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $this->postJson($mcp('ben', 'ben-pc'), ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
        $ben = $room->participants()->where('account_email', 'ben@dev.io')->firstOrFail();

        // User 1 prompts agent 1 locally: agent 1 is briefed on the group and how to split the work.
        $brief = $this->postJson($hook('ana', 'ana-laptop'), ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'Add signup: API in Laravel, form in React'])
            ->assertOk()
            ->assertJsonPath('hookSpecificOutput.hookEventName', 'UserPromptSubmit')
            ->json('hookSpecificOutput.additionalContext');
        $this->assertStringContainsString("Ben on ben-pc (id {$ben->id})", $brief);
        $this->assertStringContainsString('send_group_message', $brief);
        $this->assertSame('Started: Add signup: API in Laravel, form in React', $room->messages()->latest('id')->value('content'));

        // Agent 1 hands agent 2 its part. Ben has not opted in, so it is context, not a task.
        $this->postJson($mcp('ana', 'ana-laptop'), ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => [
            'name' => 'send_group_message',
            'arguments' => ['body' => 'Your part: build the React signup form against POST /api/signup.', 'target_agent' => $ben->id],
        ]])->assertJsonPath('result.isError', false);
        $this->postJson($hook('ben', 'ben-pc'), ['hook_event_name' => 'Stop'])->assertExactJson([]);

        // When User 2 next prompts agent 2, it learns what agent 1 started and the part handed to it.
        $benBrief = $this->postJson($hook('ben', 'ben-pc'), ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'What should I do?'])
            ->json('hookSpecificOutput.additionalContext');
        $this->assertStringContainsString("Ana's agent started: Add signup", $benBrief);
        $this->assertStringContainsString('build the React signup form', $benBrief);

        // Only Ben, signed in, can let teammates give his agent tasks.
        $this->withSession(['agent_host.authenticated' => true, 'agent_host.user' => ['email' => 'ana@dev.io', 'role' => 'admin']])
            ->patchJson("/api/concord/shared/{$room->share_token}/participants/{$ben->id}", ['accepts_team_tasks' => true])
            ->assertForbidden();
        $this->withSession(['agent_host.authenticated' => true, 'agent_host.user' => ['email' => 'ben@dev.io', 'role' => 'admin']])
            ->patchJson("/api/concord/shared/{$room->share_token}/participants/{$ben->id}", ['accepts_team_tasks' => true])
            ->assertOk();

        // Now agent 1's handoff runs on agent 2 as soon as it finishes its turn.
        $this->postJson($mcp('ana', 'ana-laptop'), ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
            'name' => 'send_group_message',
            'arguments' => ['body' => 'Also add client-side email validation.', 'target_agent' => $ben->id],
        ]]);
        $this->postJson($hook('ben', 'ben-pc'), ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Read the plan.'])
            ->assertJsonPath('decision', 'block')
            ->assertSee('Also add client-side email validation.');

        // Agent 1 finishes on its own computer; the group sees it and agent 2 gets it as context.
        $this->postJson($hook('ana', 'ana-laptop'), ['hook_event_name' => 'Stop', 'last_assistant_message' => 'POST /api/signup is live and validated.']);
        $this->assertSame('Finished: POST /api/signup is live and validated.', $room->messages()->where('kind', 'agent_status')->latest('id')->value('content'));
        $this->assertTrue($room->localTasks()->where('target_participant_id', $ben->id)->where('kind', 'context')->where('instructions', 'like', '%POST /api/signup is live%')->exists());
    }
}
