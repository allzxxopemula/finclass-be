<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PembayaranKas extends Model
{
    use HasFactory;

    protected $table = 'pembayaran_kas'; // sesuaikan dengan nama tabelmu

protected $fillable = [
    'siswa_id',
    'kelas_id', // <-- Tambahkan ini
    'minggu_ke',
    'bulan',
    'tahun',
    'jumlah_bayar',
    'status',
    'tanggal_bayar',
];
}