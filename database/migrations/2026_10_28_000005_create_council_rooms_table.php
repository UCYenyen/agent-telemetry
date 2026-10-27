<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('council_rooms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('status');
            $table->string('status_detail')->default('');
            $table->json('progress')->nullable();
            $table->timestamps();
        });

        Schema::create('council_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('council_room_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('sender');
            $table->string('icon')->nullable();
            $table->string('color')->nullable();
            $table->string('model')->nullable();
            $table->text('anon_legend')->nullable();
            $table->text('content');
            $table->float('elapsed_sec')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('council_messages');
        Schema::dropIfExists('council_rooms');
    }
};
