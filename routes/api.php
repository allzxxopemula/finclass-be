<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\KasController;
use App\Http\Controllers\Api\KelasController;
use App\Http\Controllers\Api\PenarikanController;
use App\Http\Controllers\Api\SiswaController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\ChatController;

// Endpoint autentikasi berdiri sendiri agar tidak bercampur dengan transaksi kas.
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// Ringkasan home mengambil data terhitung dari database.
Route::get('/dashboard', [DashboardController::class, 'show']);

// Endpoint pengelolaan kelas.
Route::post('/buat-kelas', [KelasController::class, 'store']);
Route::post('/join-kelas', [KelasController::class, 'join']);
Route::post('/update-kelas', [KelasController::class, 'update']);
Route::post('/update-nominal', [KelasController::class, 'updateNominal']);

// Profil & Proteksi
Route::post('/update-profile', [KasController::class, 'updateProfile']);
Route::post('/update-password', [KasController::class, 'updatePassword']);
// Siswa CRUD
Route::post('/tambah-siswa', [SiswaController::class, 'store']);
Route::put('/update-siswa/{id}', [SiswaController::class, 'update']);
Route::delete('/hapus-siswa/{id}', [SiswaController::class, 'destroy']);

// Transaksi
Route::get('/penarikan-log', [PenarikanController::class, 'index']);
Route::post('/penarikan/buku', [PenarikanController::class, 'createBook']);
Route::delete('/penarikan/buku/{tahun}/{bulan}', [PenarikanController::class, 'destroyBook']);
Route::get('/penarikan-form', [PenarikanController::class, 'form']);
Route::post('/penarikan', [PenarikanController::class, 'store']);
Route::patch('/penarikan/{tanggal}/siswa/{siswaId}', [PenarikanController::class, 'updateDetail']);
Route::get('/riwayat', [KasController::class, 'getRiwayat']);
Route::get('/aktivitas', [AuditController::class, 'index']);
Route::post('/tambah-pengeluaran', [KasController::class, 'tambahPengeluaran']);

// Room chat kelas
Route::get('/chat-room', [ChatController::class, 'index']);
Route::post('/chat-room/send', [ChatController::class, 'store']);

// hapus room
Route::post('/keluar-kelas', [KasController::class, 'keluarKelas']);
Route::post('/hapus-kelas', [KasController::class, 'hapusKelas']);

Route::post('/tambah-saldo-awal', [KasController::class, 'tambahSaldoAwal']);
