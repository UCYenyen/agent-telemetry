<?php

namespace Tests\Feature;

use App\Models\ConcordRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConcordHubTest extends TestCase
{
    use RefreshDatabase;

    protected function authenticateAdmin(): static
    {
        return $this->withSession([
            'agent_host.authenticated' => true,
            'agent_host.user' => [
                'name' => 'UCYenyen',
                'username' => 'ucyenyen',
                'email' => 'bryankunhahaha@gmail.com',
                'role' => 'admin',
            ],
        ]);
    }

    protected function authenticateRegularUser(string $email = 'member@partner.dev', string $role = 'no_role'): static
    {
        return $this->withSession([
            'agent_host.authenticated' => true,
            'agent_host.user' => [
                'name' => 'Regular User',
                'username' => 'regular_user',
                'email' => $email,
                'role' => $role,
            ],
        ]);
    }

    public function test_state_creates_default_concord_room_and_hides_vps_sessions_from_non_admin(): void
    {
        $adminRes = $this->authenticateAdmin()->getJson('/api/state');
        $adminRes->assertOk()
            ->assertJsonPath('user.role', 'admin')
            ->assertJsonPath('concord.rooms.0.title', 'Concord Multi-Agent Shared Workspace');

        $room = ConcordRoom::firstOrFail();
        $this->assertGreaterThanOrEqual(2, $room->participants()->count());

        // Non-admin user cannot view or create VPS Fleet sessions
        $regularRes = $this->authenticateRegularUser()->getJson('/api/state');
        $regularRes->assertOk()
            ->assertJsonPath('user.role', 'no_role')
            ->assertJsonPath('sessions', []);

        $this->authenticateRegularUser()
            ->postJson('/api/sessions', ['title' => 'test', 'agent' => 'claude', 'workdir' => '/tmp'])
            ->assertStatus(403);

        // Non-admin user cannot create a Group Chat project
        $this->authenticateRegularUser()
            ->postJson('/api/concord/rooms', ['title' => 'Unauthorized Room'])
            ->assertStatus(403);
    }

    public function test_three_role_rbac_and_link_edit_bypass_work(): void
    {
        $this->authenticateAdmin()->getJson('/api/state');
        $room = ConcordRoom::firstOrFail();

        // 1. Admin restricts room to invite_only
        $this->authenticateAdmin()->patchJson("/api/concord/rooms/{$room->id}", [
            'access_mode' => 'invite_only',
        ])->assertOk();

        // Uninvited visitor cannot view invite_only room
        $this->flushSession();
        $this->getJson("/api/concord/shared/{$room->share_token}")
            ->assertStatus(403)
            ->assertJsonPath('requires_email', true);

        // 2. Admin invites viewer@partner.dev with default no_role (view-only)
        $noRoleInvite = $this->authenticateAdmin()->postJson("/api/concord/rooms/{$room->id}/invites", [
            'email' => 'viewer@partner.dev',
            'role' => 'no_role',
        ])->assertCreated();

        $inviteId = $noRoleInvite->json('invite.id');

        // viewer@partner.dev can view but CANNOT send prompts because project role is no_role
        $this->flushSession();
        $this->getJson("/api/concord/shared/{$room->share_token}?email=viewer@partner.dev")
            ->assertOk()
            ->assertJsonPath('project_role', 'no_role')
            ->assertJsonPath('permission', 'view');

        $this->postJson("/api/concord/shared/{$room->share_token}/messages?email=viewer@partner.dev", [
            'body' => 'Try editing with no_role',
        ])->assertStatus(403);

        // 3. Admin upgrades viewer@partner.dev to team_member
        $this->authenticateAdmin()->patchJson("/api/concord/rooms/{$room->id}/invites/{$inviteId}", [
            'role' => 'team_member',
        ])->assertOk()
            ->assertJsonPath('invite.role', 'team_member');

        $this->flushSession();
        $this->getJson("/api/concord/shared/{$room->share_token}?email=viewer@partner.dev")
            ->assertOk()
            ->assertJsonPath('project_role', 'team_member')
            ->assertJsonPath('permission', 'edit');

        // 4. When Admin switches room to link_edit ("Anyone with the link can edit"), role check is bypassed for everyone
        $this->authenticateAdmin()->patchJson("/api/concord/rooms/{$room->id}", [
            'access_mode' => 'link_edit',
        ])->assertOk();

        $this->flushSession();
        $this->getJson("/api/concord/shared/{$room->share_token}")
            ->assertOk()
            ->assertJsonPath('permission', 'edit')
            ->assertJsonPath('role_bypassed_by_link', true);
    }

    public function test_multi_agent_group_chat_handoff_queues_local_edits_per_user_computer_and_updates_vps_memory(): void
    {
        Http::fake();

        $this->authenticateAdmin()->getJson('/api/state');
        $room = ConcordRoom::firstOrFail();

        $this->authenticateAdmin()->patchJson("/api/concord/rooms/{$room->id}", [
            'access_mode' => 'link_edit',
        ])->assertOk();

        $response = $this->postJson("/api/concord/shared/{$room->share_token}/messages", [
            'sender_name' => 'User 1 (Bryan)',
            'sender_device' => 'User-1-Workstation',
            'target_agent' => 'all',
            'body' => 'Implement the shared telemetry sync endpoint and update the React client.',
        ]);

        $response->assertOk();

        $room->refresh();
        $tasks = $room->localTasks()->orderBy('created_at')->get();
        $this->assertGreaterThanOrEqual(2, $tasks->count());

        // Task 1 targets User 1's computer, Task 2 targets User 2's computer
        $this->assertSame('User-1-Workstation', $tasks[0]->target_device);
        $this->assertSame('User-2-Laptop', $tasks[1]->target_device);

        // User 1's local connector pulls and completes its queued task
        $pullRes = $this->getJson("/api/concord/shared/{$room->share_token}/tasks/pull?participant_id={$tasks[0]->target_participant_id}")
            ->assertOk();

        $pulledTaskId = $pullRes->json('task.id');
        $this->assertSame($tasks[0]->id, $pulledTaskId);

        $this->postJson("/api/concord/shared/{$room->share_token}/tasks/{$pulledTaskId}/complete", [
            'status' => 'completed',
            'modified_files' => ['app/Services/ConcordHubService.php'],
            'result_summary' => 'Applied local edit on User-1-Workstation.',
        ])->assertOk()
            ->assertJsonPath('task.status', 'completed');
    }

    public function test_remote_mcp_endpoint_exposes_shared_memory_tools(): void
    {
        $this->authenticateAdmin()->getJson('/api/state');
        $room = ConcordRoom::firstOrFail();

        $this->postJson("/api/concord/mcp/{$room->mcp_token}", [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])->assertOk()
            ->assertJsonPath('result.tools.0.name', 'get_shared_memory');
    }
}
