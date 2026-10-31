<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('concord_local_tasks', function (Blueprint $table) {
            $table->string('from_email')->nullable()->after('from_sender');
            $table->text('reply')->nullable()->after('result_summary');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('completed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('concord_local_tasks', function (Blueprint $table) {
            $table->dropColumn(['from_email', 'reply', 'delivered_at', 'completed_at']);
        });
    }
};
