<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Satu baris mewakili satu sesi penarikan kas pada satu minggu.
        Schema::create('penarikan_kas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kelas_id')->constrained('kelas')->cascadeOnDelete();
            $table->unsignedInteger('minggu_ke');
            $table->decimal('total_nominal', 12, 2)->default(0);
            $table->timestamp('dikonfirmasi_pada')->useCurrent();
            $table->timestamps();
            $table->unique(['kelas_id', 'minggu_ke']);
        });

        // Semua siswa dicatat, termasuk yang belum membayar, agar tunggakan tidak hilang.
        Schema::create('penarikan_kas_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('penarikan_kas_id')->constrained('penarikan_kas')->cascadeOnDelete();
            $table->foreignId('siswa_id')->constrained('siswas')->cascadeOnDelete();
            $table->decimal('nominal', 10, 2)->default(0);
            $table->boolean('sudah_bayar')->default(false);
            $table->timestamp('dibayar_pada')->nullable();
            $table->timestamps();
            $table->unique(['penarikan_kas_id', 'siswa_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penarikan_kas_detail');
        Schema::dropIfExists('penarikan_kas');
    }
};
