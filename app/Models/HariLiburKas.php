<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HariLiburKas extends Model
{
    protected $table = 'hari_libur_kas';

    protected $fillable = [
        'kelas_id',
        'user_id',
        'tanggal',
        'catatan',
    ];

    protected $casts = [
        'tanggal' => 'date:Y-m-d',
    ];
}