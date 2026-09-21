<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
public function up(): void
{
    Schema::create('kelas', function (Blueprint $table) {
        $table->id();
        $table->string('nama_kelas'); // Contoh: XI RPL 1
        $table->decimal('nominal_mingguan', 10, 2)->default(5000);
        $table->string('hari_penarikan')->default('Rabu');
        $table->string('kode_akses_publik')->unique(); // Untuk link transparansi publik
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kelas');
    }
};
