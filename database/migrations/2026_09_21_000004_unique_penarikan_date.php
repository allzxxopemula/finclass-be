<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu kelas hanya boleh memiliki satu catatan pada satu tanggal penarikan.
        Schema::table('penarikan_kas', function (Blueprint $table) {
            $table->unique(['kelas_id', 'tanggal_penarikan'], 'penarikan_kas_kelas_tanggal_unique');
        });
    }

    public function down(): void
    {
        Schema::table('penarikan_kas', function (Blueprint $table) {
            $table->dropUnique('penarikan_kas_kelas_tanggal_unique');
        });
    }
};
