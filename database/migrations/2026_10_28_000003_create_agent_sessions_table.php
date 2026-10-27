<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->unique();
            $table->string('title');
            $table->string('agent');
            $table->string('model')->default('');
            $table->boolean('use_openrouter')->default(false);
            $table->string('remote_url')->nullable();
            $table->string('state')->default('idle');
            $table->boolean('fallback_enabled')->default(true);
            $table->boolean('fallback_triggered')->default(false);
            $table->unsignedTinyInteger('fallback_stage')->default(0);
            $table->text('last_prompt')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('session_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('agent_session_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->text('content');
            $table->string('model')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('session_messages');
        Schema::dropIfExists('agent_sessions');
    }
};
