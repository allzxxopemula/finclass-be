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
    Schema::create('pembayaran_kas', function (Blueprint $table) {
        $table->id();
        $table->foreignId('siswa_id')->constrained('siswas')->onDelete('cascade');
        $table->integer('minggu_ke'); // Minggu 1, 2, dst.
        $table->string('bulan'); // Contoh: September
        $table->integer('tahun');
        $table->decimal('jumlah_bayar', 10, 2);
        $table->enum('status', ['lunas', 'tunggakan'])->default('tunggakan');
        $table->timestamp('tanggal_bayar')->nullable();
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pembayaran_kas');
    }
};
