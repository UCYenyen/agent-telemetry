<?php

namespace Tests\Feature;

use App\Models\ConcordRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
}
