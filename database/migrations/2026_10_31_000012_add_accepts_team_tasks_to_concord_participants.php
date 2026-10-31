<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Owner opt-in: run tasks that teammates and their agents send, not just the owner's own prompts.
        Schema::table('concord_participants', function (Blueprint $table) {
            $table->boolean('accepts_team_tasks')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('concord_participants', function (Blueprint $table) {
            $table->dropColumn('accepts_team_tasks');
        });
    }
};
