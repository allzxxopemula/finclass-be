<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pengeluaran extends Model
{
    use HasFactory;

    protected $table = 'pengeluarans'; // Sesuaikan dengan nama tabel di database

    protected $fillable = [
        'kelas_id',   // <-- Tambahkan baris ini
        'deskripsi',
        'nominal',
    ];
}