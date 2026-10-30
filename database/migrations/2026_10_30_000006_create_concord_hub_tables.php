<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('concord_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('owner_email')->default('bryankunhahaha@gmail.com');
            $table->string('access_mode')->default('link_edit'); // invite_only | link_view | link_edit
            $table->string('share_token', 64)->unique();
            $table->string('mcp_token', 64)->unique();
            $table->string('status')->default('active');
            $table->string('status_detail')->default('Shared memory active — waiting for local agents & collaborators');
            $table->json('shared_memory')->nullable(); // Shared context window: decisions, contracts, notes, file states
            $table->json('active_claims')->nullable(); // Concord file claims across user machines
            $table->timestamps();
        });

        Schema::create('concord_room_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('concord_room_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('role')->default('no_role'); // no_role | team_member | admin
            $table->string('invite_token', 64)->unique();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('concord_participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('concord_room_id')->constrained()->cascadeOnDelete();
            $table->string('kind')->default('agent'); // agent | human
            $table->string('name'); // e.g. "Claude Code (User 1)"
            $table->string('user_name')->default('Operator');
            $table->string('account_email')->nullable();
            $table->string('device_name')->default('Local Machine'); // e.g. "User 1 MacBook", "User 2 PC"
            $table->string('execution_mode')->default('local_device'); // local_device | cloud_relay
            $table->string('provider')->default('claude'); // claude | antigravity | openrouter | codex | cursor | human
            $table->string('model')->default('claude-sonnet-5-5-high');
            $table->string('avatar')->default('AG');
            $table->string('color')->default('#ff5722');
            $table->text('role_prompt')->nullable();
            $table->boolean('use_openrouter')->default(false);
            $table->string('status')->default('idle'); // idle | thinking | editing_local | offline
            $table->text('current_thought')->nullable();
            $table->string('current_action')->nullable();
            $table->json('thinking_steps')->nullable();
            $table->json('claimed_files')->nullable();
            $table->json('local_modified_files')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('concord_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('concord_room_id')->constrained()->cascadeOnDelete();
            $table->uuid('participant_id')->nullable();
            $table->string('sender_name');
            $table->string('sender_user')->nullable();
            $table->string('sender_device')->nullable();
            $table->string('sender_kind')->default('agent');
            $table->string('sender_icon')->default('AG');
            $table->string('sender_color')->default('#ff5722');
            $table->string('model')->nullable();
            $table->string('kind')->default('message'); // message | prompt | agent_talk | handoff | local_edit | memory_update | claim
            $table->string('target_agent')->nullable();
            $table->unsignedBigInteger('reply_to_id')->nullable();
            $table->text('thinking_text')->nullable();
            $table->json('tool_calls')->nullable();
            $table->json('local_changes')->nullable(); // files modified or patches for target user's computer
            $table->text('content');
            $table->float('elapsed_sec')->nullable();
            $table->timestamps();
        });

        Schema::create('concord_local_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('concord_room_id')->constrained()->cascadeOnDelete();
            $table->uuid('target_participant_id')->nullable();
            $table->string('target_agent_name')->nullable();
            $table->string('target_device')->nullable();
            $table->string('from_sender')->default('Operator');
            $table->string('kind')->default('prompt'); // prompt | agent_handoff | apply_changes
            $table->text('instructions');
            $table->json('context_memory_snapshot')->nullable();
            $table->json('file_patches')->nullable(); // [{path, action, content_or_diff}]
            $table->string('status')->default('queued'); // queued | executing_on_device | completed | failed
            $table->json('modified_files')->nullable();
            $table->text('result_summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('concord_local_tasks');
        Schema::dropIfExists('concord_messages');
        Schema::dropIfExists('concord_participants');
        Schema::dropIfExists('concord_room_invites');
        Schema::dropIfExists('concord_rooms');
    }
};
