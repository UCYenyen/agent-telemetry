<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concord_participants', function (Blueprint $table) {
            $table->text('current_prompt')->nullable()->after('current_action');
            $table->timestamp('current_prompt_at')->nullable()->after('current_prompt');
            $table->json('activity')->nullable()->after('current_prompt_at');
        });
    }

    public function down(): void
    {
        Schema::table('concord_participants', function (Blueprint $table) {
            $table->dropColumn(['current_prompt', 'current_prompt_at', 'activity']);
        });
    }
};
