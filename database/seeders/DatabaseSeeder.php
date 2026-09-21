<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Kelas;
use App\Models\Siswa;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Buat Data Kelas
        $kelas = Kelas::create([
            'nama_kelas' => 'XI RPL 1',
            'nominal_mingguan' => 5000,
            'hari_penarikan' => 'Rabu',
            'kode_akses_publik' => 'RPL1-PAS'
        ]);

        // 2. Akun Bendahara Dummy
        User::create([
            'kelas_id' => $kelas->id,
            'name' => 'Aldo Bendahara',
            'email' => 'bendahara@gmail.com',
            'password' => Hash::make('password123'),
            'role' => 'bendahara'
        ]);

        // 3. Akun Wali Kelas Dummy
        User::create([
            'kelas_id' => $kelas->id,
            'name' => 'Pak Guru RPL',
            'email' => 'walikelas@gmail.com',
            'password' => Hash::make('password123'),
            'role' => 'wali_kelas'
        ]);

        // 4. Akun Siswa Dummy & Data Siswa
        $userSiswa = User::create([
            'kelas_id' => $kelas->id,
            'name' => 'Budi Santoso',
            'email' => 'siswa@gmail.com',
            'password' => Hash::make('password123'),
            'role' => 'siswa'
        ]);

        Siswa::create([
            'kelas_id' => $kelas->id,
            'user_id' => $userSiswa->id,
            'nama_siswa' => 'Budi Santoso',
            'nomor_wa' => '081234567890'
        ]);

        Siswa::create([
            'kelas_id' => $kelas->id,
            'nama_siswa' => 'Siti Aminah',
            'nomor_wa' => '089876543210'
        ]);
    }
}