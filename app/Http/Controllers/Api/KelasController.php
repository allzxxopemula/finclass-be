<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class KelasController extends Controller
{
    // Membuat kelas baru dan langsung menghubungkannya dengan bendahara.
    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,id',
            'nama_kelas' => 'required|string|max:255',
            'nominal_mingguan' => 'required|numeric|min:1',
            'hari_penarikan' => 'required|in:Minggu,Senin,Selasa,Rabu,Kamis,Jumat,Sabtu',
        ]);
        $kelas = Kelas::create([
            'nama_kelas' => $data['nama_kelas'],
            'nominal_mingguan' => $data['nominal_mingguan'],
            'hari_penarikan' => $data['hari_penarikan'],
            'kode_akses_publik' => strtoupper(Str::random(6)),
        ]);
        User::whereKey($data['user_id'])->update(['kelas_id' => $kelas->id]);
        AuditLog::record($data['user_id'], 'Membuat kelas '.$kelas->nama_kelas);

        return response()->json(['status' => 'success', 'kelas' => $kelas, 'user' => User::find($data['user_id'])]);
    }

    // Menghubungkan user ke kelas berdasarkan kode akses.
    public function join(Request $request)
    {
        $data = $request->validate(['user_id' => 'required|exists:users,id', 'kode_akses_publik' => 'required|string']);
        $kelas = Kelas::where('kode_akses_publik', strtoupper($data['kode_akses_publik']))->first();
        if (!$kelas) return response()->json(['status' => 'error', 'message' => 'Kode kelas tidak ditemukan!'], 404);

        $user = User::findOrFail($data['user_id']);
        // Bergabung hanya memberi akses melihat kelas; bendahara menambahkan anggota secara manual.
        $user->update(['kelas_id' => $kelas->id]);
        AuditLog::record($user->id, 'Bergabung ke kelas '.$kelas->nama_kelas);

        return response()->json(['status' => 'success', 'kelas' => $kelas, 'user' => $user->fresh()]);
    }

    // Mengubah nama dan kode akses kelas.
    public function update(Request $request)
    {
        $data = $request->validate([
            'kelas_id' => 'required|exists:kelas,id', 'user_id' => 'required|exists:users,id', 'nama_kelas' => 'required|string|max:255',
            'kode_akses_publik' => 'required|string|max:50|unique:kelas,kode_akses_publik,' . $request->kelas_id,
            'hari_penarikan' => 'required|in:Minggu,Senin,Selasa,Rabu,Kamis,Jumat,Sabtu',
        ]);
        $kelas = Kelas::findOrFail($data['kelas_id']);
        $kelas->update([
            'nama_kelas' => $data['nama_kelas'],
            'kode_akses_publik' => strtoupper($data['kode_akses_publik']),
            'hari_penarikan' => $data['hari_penarikan'],
        ]);
        AuditLog::record($data['user_id'], 'Mengubah pengaturan kelas menjadi '.$kelas->nama_kelas);
        return response()->json(['status' => 'success', 'message' => 'Pengaturan kelas berhasil diperbarui!', 'kelas' => $kelas]);
    }

    // Mengubah nominal wajib setiap minggu.
    public function updateNominal(Request $request)
    {
        $data = $request->validate(['kelas_id' => 'required|exists:kelas,id', 'user_id' => 'required|exists:users,id', 'nominal_mingguan' => 'required|numeric|min:1']);
        abort_unless(User::whereKey($data['user_id'])->where('kelas_id', $data['kelas_id'])->where('role', 'bendahara')->exists(), 403, 'Hanya bendahara yang dapat mengubah nominal kas.');
        $kelas = Kelas::findOrFail($data['kelas_id']);
        $kelas->update(['nominal_mingguan' => $data['nominal_mingguan']]);
        AuditLog::record($data['user_id'], 'Mengubah nominal kas kelas');
        return response()->json(['status' => 'success', 'message' => 'Nominal kas berhasil diperbarui!', 'kelas' => $kelas]);
    }
}
