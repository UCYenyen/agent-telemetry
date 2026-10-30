<?php

namespace Tests\Feature;

use App\Models\ConcordRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConcordHubTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticate(): static
    {
        return $this->withSession(['agent_host.authenticated' => true]);
    }

    public function test_state_creates_default_concord_room_with_shared_memory_and_participants(): void
    {
        $response = $this->authenticate()->getJson('/api/state');

        $response->assertOk()
            ->assertJsonPath('concord.rooms.0.slug', 'cross-account-hub');

        $room = ConcordRoom::firstOrFail();
        $this->assertGreaterThanOrEqual(2, $room->participants()->count());
        $this->assertArrayHasKey('architecture', $room->shared_memory);
        $this->assertArrayHasKey('contracts', $room->shared_memory);
        $this->assertArrayHasKey('decisions', $room->shared_memory);
    }

    public function test_canva_google_docs_style_sharing_and_email_invites_work(): void
    {
        $this->authenticate()->getJson('/api/state');
        $room = ConcordRoom::firstOrFail();

        // Restrict room to invite_only
        $this->authenticate()->patchJson("/api/concord/rooms/{$room->id}", [
            'access_mode' => 'invite_only',
        ])->assertOk();

        // Uninvited visitor cannot view invite_only room
        $this->getJson("/api/concord/shared/{$room->share_token}")
            ->assertStatus(403)
            ->assertJsonPath('requires_email', true);

        // Invite user2@partner.dev as editor
        $inviteRes = $this->authenticate()->postJson("/api/concord/rooms/{$room->id}/invites", [
            'email' => 'user2@partner.dev',
            'role' => 'editor',
        ])->assertCreated();

        $inviteToken = $inviteRes->json('invite.invite_token');

        // Visitor with email query param can access
        $this->getJson("/api/concord/shared/{$room->share_token}?email=user2@partner.dev")
            ->assertOk()
            ->assertJsonPath('permission', 'edit');

        // Visitor with personal invite_token can access directly
        $this->getJson("/api/concord/shared/{$inviteToken}")
            ->assertOk()
            ->assertJsonPath('permission', 'edit');

        // Switch room to link_view: anyone with link can view, but not post prompts
        $this->authenticate()->patchJson("/api/concord/rooms/{$room->id}", [
            'access_mode' => 'link_view',
        ])->assertOk();

        $this->getJson("/api/concord/shared/{$room->share_token}")
            ->assertOk()
            ->assertJsonPath('permission', 'view');

        $this->postJson("/api/concord/shared/{$room->share_token}/messages", [
            'body' => 'Try editing in view-only mode',
        ])->assertStatus(403);
    }

    public function test_multi_agent_group_chat_handoff_queues_local_edits_per_user_computer_and_updates_vps_memory(): void
    {
        Http::fake();

        $this->authenticate()->getJson('/api/state');
        $room = ConcordRoom::firstOrFail();

        $response = $this->postJson("/api/concord/shared/{$room->share_token}/messages", [
            'sender_name' => 'User 1 (Bryan)',
            'sender_device' => 'Bryan-MacBook-Pro (Local)',
            'target_agent' => 'all',
            'body' => 'Implement the shared telemetry sync endpoint and update the React client.',
        ]);

        $response->assertOk();

        $room->refresh();
        $tasks = $room->localTasks()->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $tasks->count());

        // Task 1 targets User 1's computer, Task 2 targets User 2's computer
        $this->assertSame('Bryan-MacBook-Pro (Local)', $tasks[0]->target_device);
        $this->assertSame('Partner-Workstation (Local)', $tasks[1]->target_device);

        // User 1's local connector pulls and completes its queued task
        $pullRes = $this->getJson("/api/concord/shared/{$room->share_token}/tasks/pull?participant_id={$tasks[0]->target_participant_id}")
            ->assertOk();

        $pulledTaskId = $pullRes->json('task.id');
        $this->assertSame($tasks[0]->id, $pulledTaskId);

        $this->postJson("/api/concord/shared/{$room->share_token}/tasks/{$pulledTaskId}/complete", [
            'status' => 'completed',
            'modified_files' => ['app/Services/ConcordHubService.php'],
            'result_summary' => 'Applied local edit on Bryan-MacBook-Pro (Local).',
        ])->assertOk()
            ->assertJsonPath('task.status', 'completed');
    }

    public function test_remote_mcp_endpoint_exposes_shared_memory_tools(): void
    {
        $this->authenticate()->getJson('/api/state');
        $room = ConcordRoom::firstOrFail();

        $this->postJson("/api/concord/mcp/{$room->mcp_token}", [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])->assertOk()
            ->assertJsonPath('result.tools.0.name', 'get_shared_memory');
    }
}
