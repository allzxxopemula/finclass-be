<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penarikan_kas', function (Blueprint $table) {
            // Tanggal nyata menjadi identitas sesi; minggu_ke hanya dipertahankan untuk data lama.
            $table->date('tanggal_penarikan')->nullable()->after('minggu_ke');
            $table->index(['kelas_id', 'tanggal_penarikan']);
        });

        // Data lama memakai waktu konfirmasi; salin tanggalnya agar tetap bisa diedit.
        DB::statement('UPDATE penarikan_kas SET tanggal_penarikan = DATE(dikonfirmasi_pada) WHERE tanggal_penarikan IS NULL');
    }

    public function down(): void
    {
        Schema::table('penarikan_kas', function (Blueprint $table) {
            $table->dropIndex(['kelas_id', 'tanggal_penarikan']);
            $table->dropColumn('tanggal_penarikan');
        });
    }
};
