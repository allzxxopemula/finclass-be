<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PenarikanKasDetail extends Model
{
    use HasFactory;

    protected $table = 'penarikan_kas_detail';

    protected $fillable = [
        'penarikan_kas_id',
        'siswa_id',
        'nominal',
        'sudah_bayar',
        'dibayar_pada',
    ];

    protected $casts = [
        'nominal' => 'decimal:2',
        'sudah_bayar' => 'boolean',
        'dibayar_pada' => 'datetime',
    ];

    public function siswa()
    {
        return $this->belongsTo(Siswa::class);
    }

    // Relasi induk dipakai untuk menghitung tunggakan lintas minggu.
    public function penarikanKas()
    {
        return $this->belongsTo(PenarikanKas::class);
    }
}
