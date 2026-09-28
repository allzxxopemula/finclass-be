<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('users', 'custom_border_color')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('custom_border_color')->nullable()->after('banner');
            });
        }

        $borderTypeMap = [
            'allzxxott@gmail.com' => 'DEVELOPER',
            'allzxxo@gmail.com' => 'DEVELOPER',
            'developer@finclass.id' => 'DEVELOPER',
            'team@finclass.id' => 'DEV_TEAM',
            'staff@finclass.id' => 'DEV_TEAM',
            'jancok123@gmail.com' => 'DEV_TEAM',
            'ayubganda@gmail.com' => 'DEV_TEAM',
            'ayyubrashifpamungkas@gmail.com' => 'DEV_TEAM',
            'wahyuhanindio@gmail.com' => 'DONATUR',
            'aldorendyjulian@gmail.com' => 'EXCLUSIVE',
            'zeozero601@gmail.com' => 'SECRET_PURPLE',
            'leroffey@gmail.com' => 'SECRET_PINK',
        ];

        foreach ($borderTypeMap as $email => $borderType) {
            DB::table('users')
                ->whereRaw('LOWER(email) = ?', [strtolower($email)])
                ->where(function ($query) {
                    $query->whereNull('custom_border_color')
                        ->orWhere('custom_border_color', '')
                        ->orWhere('custom_border_color', 'DEVELOPER')
                        ->orWhere('custom_border_color', 'DEV_TEAM')
                        ->orWhere('custom_border_color', 'DONATUR')
                        ->orWhere('custom_border_color', 'EXCLUSIVE')
                        ->orWhere('custom_border_color', 'SECRET_PURPLE')
                        ->orWhere('custom_border_color', 'SECRET_PINK');
                })
                ->update(['custom_border_color' => $borderType]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'custom_border_color')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('custom_border_color');
            });
        }
    }
};
