<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'username')) {
                $table->string('username')->nullable()->after('email');
            }
        });

        $users = DB::table('users')->get();

        foreach ($users as $user) {
            $generated = preg_replace('/[^a-z0-9]/i', '', strtolower($user->name ?? $user->email ?? 'user')) ?: 'user';
            $candidate = $generated;
            $suffix = 1;

            while (DB::table('users')->where('username', $candidate)->where('id', '!=', $user->id)->exists()) {
                $candidate = $generated . $suffix;
                $suffix++;
            }

            DB::table('users')->where('id', $user->id)->update(['username' => $candidate]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'username')) {
                $table->dropColumn('username');
            }
        });
    }
};
