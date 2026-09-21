<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Siswa;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class SiswaController extends Controller
{
    // Menambahkan siswa manual ke daftar kelas.
    public function store(Request $request)
    {
        $data = $request->validate(['kelas_id' => 'required|exists:kelas,id', 'user_id' => 'required|exists:users,id', 'nama_siswa' => 'required|string|max:255']);
        $this->ensureTreasurer($data['user_id'], $data['kelas_id']);
        unset($data['user_id']);
        AuditLog::record($request->user_id, 'Menambahkan anggota '.$data['nama_siswa']);
        return response()->json(['status' => 'success', 'siswa' => Siswa::create($data)], 201);
    }

    // Mengubah nama siswa tanpa membuat transaksi kas palsu.
    public function update(Request $request, $id)
    {
        $siswa = Siswa::find($id);
        if (!$siswa) return response()->json(['status' => 'error', 'message' => 'Siswa tidak ditemukan!'], 404);
        $data = $request->validate(['user_id' => 'required|exists:users,id', 'nama_siswa' => 'required|string|max:255']);
        $this->ensureTreasurer($data['user_id'], $siswa->kelas_id);
        unset($data['user_id']);
        $siswa->update($data);
        AuditLog::record($request->user_id, 'Mengubah nama anggota menjadi '.$siswa->nama_siswa);
        return response()->json(['status' => 'success', 'message' => 'Nama siswa berhasil diubah!', 'siswa' => $siswa]);
    }

    // Menghapus siswa beserta detail penarikan terkait melalui cascade database.
    public function destroy($id)
    {
        $siswa = Siswa::find($id);
        if (!$siswa) return response()->json(['status' => 'error', 'message' => 'Siswa tidak ditemukan!'], 404);
        $this->ensureTreasurer(request()->input('user_id'), $siswa->kelas_id);
        AuditLog::record(request()->input('user_id'), 'Menghapus anggota '.$siswa->nama_siswa);
        $siswa->delete();
        return response()->json(['status' => 'success', 'message' => 'Siswa berhasil dihapus dari daftar anggota!']);
    }

    private function ensureTreasurer($userId, $kelasId): void
    {
        abort_unless($userId && User::whereKey($userId)->where('kelas_id', $kelasId)->where('role', 'bendahara')->exists(), 403, 'Hanya bendahara yang dapat mengelola anggota.');
    }
}
