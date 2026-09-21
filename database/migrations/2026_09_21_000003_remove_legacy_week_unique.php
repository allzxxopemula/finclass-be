<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Nomor minggu bukan identitas lagi; identitas sesi sekarang tanggal_penarikan.
        Schema::table('penarikan_kas', function (Blueprint $table) {
            $table->dropUnique('penarikan_kas_kelas_id_minggu_ke_unique');
        });
    }

    public function down(): void
    {
        Schema::table('penarikan_kas', function (Blueprint $table) {
            $table->unique(['kelas_id', 'minggu_ke']);
        });
    }
};
