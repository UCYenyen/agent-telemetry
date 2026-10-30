<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role', 30)->default('no_role')->after('phone');
            });
        }

        if (Schema::hasTable('concord_room_invites')) {
            DB::table('concord_room_invites')->where('role', 'editor')->update(['role' => 'team_member']);
            DB::table('concord_room_invites')->where('role', 'viewer')->update(['role' => 'no_role']);
        }

        if (Schema::hasTable('concord_participants')) {
            DB::table('concord_participants')->update(['avatar' => 'AG']);
        }

        if (Schema::hasTable('concord_messages')) {
            DB::table('concord_messages')->update(['sender_icon' => 'AG']);
        }

        if (Schema::hasTable('advisors')) {
            $cleanIcons = [
                'The Contrarian' => 'CN',
                'The First Principles Thinker' => 'FP',
                'The Expansionist' => 'EX',
                'The Outsider' => 'OT',
                'The Executor' => 'EC',
            ];
            foreach ($cleanIcons as $name => $icon) {
                DB::table('advisors')->where('name', $name)->update(['icon' => $icon]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('role');
            });
        }
    }
};
