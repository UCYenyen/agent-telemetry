<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Each deployed VPS session owns one group chat, and its Claude joins that group as an agent.
        Schema::table('concord_rooms', function (Blueprint $table) {
            $table->uuid('agent_session_id')->nullable()->index();
        });
        Schema::table('concord_participants', function (Blueprint $table) {
            $table->uuid('agent_session_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('concord_rooms', function (Blueprint $table) {
            $table->dropColumn('agent_session_id');
        });
        Schema::table('concord_participants', function (Blueprint $table) {
            $table->dropColumn('agent_session_id');
        });
    }
};
