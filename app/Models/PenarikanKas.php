<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PenarikanKas extends Model
{
    use HasFactory;

    protected $table = 'penarikan_kas';

    protected $fillable = [
        'kelas_id',
        'minggu_ke',
        'tanggal_penarikan',
        'total_nominal',
        'dikonfirmasi_pada',
    ];

    protected $casts = [
        'total_nominal' => 'decimal:2',
        'dikonfirmasi_pada' => 'datetime',
        'tanggal_penarikan' => 'date:Y-m-d',
    ];

    public function details()
    {
        return $this->hasMany(PenarikanKasDetail::class);
    }
}
