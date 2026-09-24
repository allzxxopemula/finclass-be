<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\PembayaranKas;
use App\Models\PenarikanKasDetail;
use App\Models\PenarikanKas;
use App\Models\Pengeluaran;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    // Menghitung ringkasan saldo dari saldo awal, penarikan, dan pengeluaran.
    public function show(Request $request)
    {
        $request->validate(['user_id' => 'required|exists:users,id']);
        $user = User::findOrFail($request->user_id);

        if (!$user->kelas_id) {
            return response()->json(['status' => 'empty', 'message' => 'Belum terhubung ke kelas.']);
        }

        $kelas = Kelas::find($user->kelas_id);
        if (!$kelas) {
            $user->update(['kelas_id' => null]);
            return response()->json(['status' => 'empty', 'message' => 'Kelas tidak ditemukan.']);
        }

        // Urutan dashboard dan Kelola Anggota mengikuti urutan input anggota.
        $siswas = Siswa::where('kelas_id', $kelas->id)->orderBy('id')->get();
        $legacyPayments = PembayaranKas::where('kelas_id', $kelas->id)
            ->whereNotNull('siswa_id')->where('minggu_ke', '>', 0)->get();
        $saldoAwal = PembayaranKas::where('kelas_id', $kelas->id)
            ->whereNull('siswa_id')->where('minggu_ke', 0)->sum('jumlah_bayar');
        $allTimeWithdrawals = PenarikanKasDetail::whereHas('penarikanKas', fn ($query) => $query->where('kelas_id', $kelas->id))
            ->where('sudah_bayar', true)->get();
        $latestSession = PenarikanKas::where('kelas_id', $kelas->id)->latest('tanggal_penarikan')->withCount(['details as jumlah_sudah_bayar' => fn ($query) => $query->where('sudah_bayar', true)])->first();

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $weekEnd = Carbon::now()->endOfWeek(Carbon::SUNDAY);

        $weeklyWithdrawals = PenarikanKasDetail::whereHas('penarikanKas', fn ($query) => $query
                ->where('kelas_id', $kelas->id)
                ->whereBetween('tanggal_penarikan', [$weekStart->toDateString(), $weekEnd->toDateString()]))
            ->where('sudah_bayar', true)
            ->sum('nominal');

        $totalPemasukan = (float) $saldoAwal + (float) $legacyPayments->sum('jumlah_bayar') + (float) $allTimeWithdrawals->sum('nominal');
        $totalPengeluaranKeseluruhan = (float) Pengeluaran::where('kelas_id', $kelas->id)->sum('nominal');
        $totalPengeluaranMingguIni = (float) Pengeluaran::where('kelas_id', $kelas->id)
            ->whereBetween('created_at', [$weekStart->copy()->startOfDay(), $weekEnd->copy()->endOfDay()])
            ->sum('nominal');

        $bendahara = User::where('kelas_id', $kelas->id)->where('role', 'bendahara')->first();
        $members = User::where('kelas_id', $kelas->id)
            ->orderBy('role', 'desc')
            ->orderBy('id')
            ->get()
            ->map(function ($member) {
                return [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'role' => $member->role,
                    'kelas_id' => $member->kelas_id,
                    'profile_image_url' => $member->profile_image_url,
                ];
            });

        return response()->json([
            'status' => 'success',
            'kelas' => $kelas,
            'bendahara' => $bendahara ? [
                'id' => $bendahara->id,
                'name' => $bendahara->name,
                'email' => $bendahara->email,
                'role' => $bendahara->role,
                'kelas_id' => $bendahara->kelas_id,
                'profile_image_url' => $bendahara->profile_image_url,
            ] : null,
            'members' => $members,
            'siswas' => $siswas,
            'saldo' => $totalPemasukan - $totalPengeluaranKeseluruhan,
            'total_pemasukan' => $totalPemasukan,
            'pemasukan_minggu_ini' => (float) $weeklyWithdrawals,
            'total_pengeluaran' => (float) $totalPengeluaranMingguIni,
            'total_pengeluaran_keseluruhan' => $totalPengeluaranKeseluruhan,
            'jumlah_siswa' => $siswas->count(),
            'jumlah_siswa_bayar' => (int) ($latestSession?->jumlah_sudah_bayar ?? 0),
        ]);
    }
}
