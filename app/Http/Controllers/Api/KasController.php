<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Kelas;
use App\Models\Siswa;
use App\Models\PembayaranKas;
use App\Models\Pengeluaran;
use App\Models\PenarikanKas;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class KasController extends Controller
{
    // =========================================================================
    // 1. AUTHENTICATION (LOGIN & REGISTER)
    // =========================================================================

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json(['status' => 'error', 'message' => 'Email atau password salah!'], 401);
        }

        return response()->json(['status' => 'success', 'message' => 'Login berhasil!', 'user' => $user]);
    }

    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'role' => 'required|in:bendahara,wali_kelas,siswa',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
        ]);

        return response()->json(['status' => 'success', 'message' => 'Registrasi berhasil!', 'user' => $user], 201);
    }

    // =========================================================================
    // 2. DASHBOARD & PROFIL USER
    // =========================================================================

    public function getDashboard(Request $request)
    {
        $userId = $request->query('user_id');
        $user = User::find($userId);

        if (!$user || !$user->kelas_id) {
            return response()->json(['status' => 'empty', 'message' => 'Belum terhubung ke kelas.']);
        }

        $kelas = Kelas::find($user->kelas_id);
        
        if (!$kelas) {
            $user->kelas_id = null;
            $user->save();
            return response()->json(['status' => 'empty', 'message' => 'Kelas tidak ditemukan.']);
        }

        // Ambil HANYA siswa asli (abaikan data dummy jika ada)
        $siswas = Siswa::where('kelas_id', $user->kelas_id)
            ->where('nama_siswa', 'NOT LIKE', '%KAS AWAL%')
            ->where('nama_siswa', 'NOT LIKE', '%(Dihapus)%')
            ->get();

        $pembayaran = PembayaranKas::whereIn('siswa_id', $siswas->pluck('id'))->get();
        
        $siswas = $siswas->map(function($siswa) use ($pembayaran) {
            $siswa->pembayaran_kas = $pembayaran->where('siswa_id', $siswa->id)->values();
            return $siswa;
        });

        // Ambil saldo awal HANYA berdasarkan kelas_id yang sedang aktif
        $totalSaldoAwal = PembayaranKas::where('kelas_id', $user->kelas_id)
            ->whereNull('siswa_id')
            ->where('minggu_ke', 0)
            ->sum('jumlah_bayar');

        $totalPemasukanSiswa = $pembayaran->where('minggu_ke', '>=', 0)->sum('jumlah_bayar');
        $totalPemasukan = $totalPemasukanSiswa + $totalSaldoAwal;

        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $weekEnd = Carbon::now()->endOfWeek(Carbon::SUNDAY);

        $weeklyWithdrawals = \App\Models\PenarikanKasDetail::whereHas('penarikanKas', fn ($query) => $query
                ->where('kelas_id', $user->kelas_id)
                ->whereBetween('tanggal_penarikan', [$weekStart->toDateString(), $weekEnd->toDateString()]))
            ->where('sudah_bayar', true)
            ->sum('nominal');

        $totalPengeluaranKeseluruhan = Pengeluaran::where('kelas_id', $user->kelas_id)->sum('nominal');
        $totalPengeluaranMingguIni = Pengeluaran::where('kelas_id', $user->kelas_id)
            ->whereBetween('created_at', [$weekStart->copy()->startOfDay(), $weekEnd->copy()->endOfDay()])
            ->sum('nominal');

        $saldoUtama = $totalPemasukan - $totalPengeluaranKeseluruhan;

        return response()->json([
            'status' => 'success',
            'kelas' => $kelas,
            'siswas' => $siswas,
            'saldo' => $saldoUtama,
            'pemasukan_minggu_ini' => (float) $weeklyWithdrawals,
            'total_pengeluaran' => (float) $totalPengeluaranMingguIni,
            'total_pengeluaran_keseluruhan' => $totalPengeluaranKeseluruhan,
        ]);
    }

    public function updateProfile(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'name' => 'required|string|max:255',
            'username' => 'nullable|string|max:40',
            'profile_image_url' => 'nullable|string|url|max:2048',
        ]);

        $user = User::find($request->user_id);
        $user->name = $request->name;

        if ($request->filled('username')) {
            $user->username = trim($request->username);
        }

        if ($request->filled('profile_image_url')) {
            $user->profile_image_url = $request->profile_image_url;
        }

        $user->save();

        $logMessage = 'Mengubah profil ' . $user->name;
        if ($request->filled('profile_image_url')) {
            $logMessage .= ' dengan foto profil baru';
        }

        AuditLog::record($request->user_id, $logMessage);

        return response()->json([
            'status' => 'success',
            'message' => 'Profil berhasil diperbarui!',
            'user' => $user
        ]);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'password_lama' => 'required',
            'password_baru' => 'required|min:6',
        ]);

        $user = User::find($request->user_id);

        if (!Hash::check($request->password_lama, $user->password)) {
            return response()->json([
                'status' => 'error',
                'message' => 'Password lama tidak sesuai!'
            ], 400);
        }

        $user->password = Hash::make($request->password_baru);
        $user->save();
        AuditLog::record($request->user_id, 'Mengubah password akun');

        return response()->json([
            'status' => 'success',
            'message' => 'Password berhasil diperbarui!'
        ]);
    }

    // =========================================================================
    // 3. MANAJEMEN KELAS & NOMINAL
    // =========================================================================

    public function buatKelas(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'nama_kelas' => 'required|string',
            'nominal_mingguan' => 'required|numeric',
        ]);

        $kelas = Kelas::create([
            'nama_kelas' => $request->nama_kelas,
            'nominal_mingguan' => $request->nominal_mingguan,
            'hari_penarikan' => 'Rabu',
            'kode_akses_publik' => strtoupper(Str::random(6))
        ]);

        $user = User::find($request->user_id);
        $user->kelas_id = $kelas->id;
        $user->save();

        return response()->json(['status' => 'success', 'kelas' => $kelas, 'user' => $user]);
    }

    public function joinKelas(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'kode_akses_publik' => 'required|string',
        ]);

        $kelas = Kelas::where('kode_akses_publik', strtoupper($request->kode_akses_publik))->first();
        if (!$kelas) {
            return response()->json(['status' => 'error', 'message' => 'Kode kelas tidak ditemukan!'], 404);
        }

        $user = User::find($request->user_id);
        $user->kelas_id = $kelas->id;
        $user->save();

        if ($user->role === 'siswa') {
            $siswa = Siswa::create(['kelas_id' => $kelas->id, 'user_id' => $user->id, 'nama_siswa' => $user->name]);
            
            PembayaranKas::create([
                'siswa_id' => $siswa->id,
                'kelas_id' => $kelas->id,
                'minggu_ke' => -1,
                'bulan' => date('F'),
                'tahun' => date('Y'),
                'jumlah_bayar' => 0,
                'status' => 'lunas', // Diubah dari 'log' ke 'lunas' agar aman dari error ENUM database
                'tanggal_bayar' => now()
            ]);
        }

        return response()->json(['status' => 'success', 'kelas' => $kelas, 'user' => $user]);
    }

    public function keluarKelas(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::find($request->user_id);
        $user->kelas_id = null;
        $user->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Berhasil keluar dari kelas!',
            'user' => $user
        ]);
    }

    public function hapusKelas(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'kelas_id' => 'required|exists:kelas,id',
        ]);

        $kelasId = $request->kelas_id;

        User::where('kelas_id', $kelasId)->update(['kelas_id' => null]);

        $siswaIds = Siswa::where('kelas_id', $kelasId)->pluck('id');
        PembayaranKas::whereIn('siswa_id', $siswaIds)->delete();
        PembayaranKas::where('kelas_id', $kelasId)->whereNull('siswa_id')->delete();
        Pengeluaran::where('kelas_id', $kelasId)->delete();
        Siswa::where('kelas_id', $kelasId)->delete();

        Kelas::destroy($kelasId);

        $user = User::find($request->user_id);

        return response()->json([
            'status' => 'success',
            'message' => 'Room kelas berhasil dihapus permanen!',
            'user' => $user
        ]);
    }

    public function updateNominal(Request $request)
    {
        $request->validate([
            'kelas_id' => 'required|exists:kelas,id',
            'nominal_mingguan' => 'required|numeric'
        ]);

        $kelas = Kelas::find($request->kelas_id);
        $kelas->nominal_mingguan = $request->nominal_mingguan;
        $kelas->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Nominal kas berhasil diperbarui!',
            'kelas' => $kelas
        ]);
    }

    // =========================================================================
    // 4. MANAJEMEN SISWA (CRUD)
    // =========================================================================

    public function tambahSiswa(Request $request)
    {
        $request->validate([
            'kelas_id' => 'required|exists:kelas,id',
            'nama_siswa' => 'required|string'
        ]);

        $siswa = Siswa::create(['kelas_id' => $request->kelas_id, 'nama_siswa' => $request->nama_siswa]);

        // Catat aktivitas ke riwayat
        PembayaranKas::create([
            'siswa_id' => $siswa->id,
            'kelas_id' => $request->kelas_id,
            'minggu_ke' => -1,
            'bulan' => date('F'),
            'tahun' => date('Y'),
            'jumlah_bayar' => 0,
            'status' => 'lunas', // Diubah dari 'log' ke 'lunas' agar aman dari error ENUM database
            'tanggal_bayar' => now()
        ]);

        return response()->json(['status' => 'success', 'siswa' => $siswa]);
    }

    public function updateSiswa(Request $request, $id)
    {
        $request->validate([
            'nama_siswa' => 'required|string'
        ]);

        $siswa = Siswa::find($id);
        
        if (!$siswa) {
            return response()->json(['status' => 'error', 'message' => 'Siswa tidak ditemukan!'], 404);
        }

        $namaLama = $siswa->nama_siswa;
        $siswa->nama_siswa = $request->nama_siswa;
        $siswa->save();

        PembayaranKas::create([
            'siswa_id' => $siswa->id,
            'kelas_id' => $siswa->kelas_id,
            'minggu_ke' => -2,
            'bulan' => date('F'),
            'tahun' => date('Y'),
            'jumlah_bayar' => 0,
            'status' => 'lunas', // Menggunakan 'lunas' agar valid di database
            'tanggal_bayar' => now()
        ]);

        return response()->json(['status' => 'success', 'message' => 'Nama siswa berhasil diubah!', 'siswa' => $siswa]);
    }

    public function hapusSiswa($id)
    {
        $siswa = Siswa::find($id);
        
        if (!$siswa) {
            return response()->json(['status' => 'error', 'message' => 'Siswa tidak ditemukan!'], 404);
        }

        PembayaranKas::where('siswa_id', $id)->delete();
        $siswa->delete();

        return response()->json(['status' => 'success', 'message' => 'Siswa berhasil dihapus dari daftar anggota!']);
    }

    // =========================================================================
    // 5. TRANSAKSI, SALDO AWAL & HISTORY RIWAYAT
    // =========================================================================

    public function tambahSaldoAwal(Request $request)
    {
        $request->validate([
            'kelas_id' => 'required|exists:kelas,id',
            'user_id' => 'required|exists:users,id',
            'nominal' => 'required|numeric|min:1'
        ]);

        $pembayaran = PembayaranKas::create([
            'siswa_id' => null,
            'kelas_id' => $request->kelas_id,
            'minggu_ke' => 0,
            'bulan' => date('F'),
            'tahun' => date('Y'),
            'jumlah_bayar' => $request->nominal,
            'status' => 'lunas',
            'tanggal_bayar' => now()
        ]);

        return response()->json([
            'status' => 'success',
            'message' => 'Saldo awal kas berhasil ditambahkan!',
            'pembayaran' => $pembayaran
        ]);
    }

    public function toggleBayarKas(Request $request)
    {
        $request->validate([
            'siswa_id' => 'required|exists:siswas,id',
            'minggu_ke' => 'required|numeric',
            'nominal' => 'required|numeric'
        ]);

        $siswa = Siswa::find($request->siswa_id);
        $kelasId = $siswa ? $siswa->kelas_id : null;

        $pembayaran = PembayaranKas::where('siswa_id', $request->siswa_id)
            ->where('minggu_ke', $request->minggu_ke)
            ->first();

        if ($pembayaran) {
            $pembayaran->delete();
            return response()->json(['status' => 'success', 'action' => 'unchecked']);
        } else {
            PembayaranKas::create([
                'siswa_id' => $request->siswa_id,
                'kelas_id' => $kelasId,
                'minggu_ke' => $request->minggu_ke,
                'bulan' => date('F'),
                'tahun' => date('Y'),
                'jumlah_bayar' => $request->nominal,
                'status' => 'lunas',
                'tanggal_bayar' => now()
            ]);
            return response()->json(['status' => 'success', 'action' => 'checked']);
        }
    }

    public function getRiwayat(Request $request)
    {
        $kelasId = $request->query('kelas_id');
        
        $siswas = Siswa::where('kelas_id', $kelasId)->get();
        $siswaIds = $siswas->pluck('id');
        
        $pemasukanSiswa = PembayaranKas::whereIn('siswa_id', $siswaIds)->get()->map(function($p) use ($siswas) {
            $siswa = $siswas->where('id', $p->siswa_id)->first();
            $namaSiswa = $siswa ? $siswa->nama_siswa : 'Siswa';
            
            if ($p->minggu_ke == -1) {
                $judul = "Anggota Baru Ditambahkan: {$namaSiswa}";
            } elseif ($p->minggu_ke == -2) {
                $judul = "Update Anggota: {$namaSiswa}";
            } else {
                $judul = "Pembayaran Kas Minggu-{$p->minggu_ke} ({$namaSiswa})";
            }

            return [
                'raw_id' => $p->id,
                'id' => 'in-'.$p->id,
                'tipe' => 'masuk',
                'judul' => $judul,
                'nominal' => $p->jumlah_bayar,
                'tanggal' => $p->created_at ? $p->created_at->format('d M Y, H:i') : date('d M Y')
            ];
        });

        $saldoAwal = PembayaranKas::where('kelas_id', $kelasId)
            ->whereNull('siswa_id')
            ->where('minggu_ke', 0)
            ->get()
            ->map(function($p) {
                return [
                    'raw_id' => $p->id,
                    'id' => 'sa-'.$p->id,
                    'tipe' => 'masuk',
                    'judul' => 'Saldo Awal Kas (Buku Manual)',
                    'nominal' => $p->jumlah_bayar,
                    'tanggal' => $p->created_at ? $p->created_at->format('d M Y, H:i') : date('d M Y')
                ];
            });

        $pengeluaran = Pengeluaran::where('kelas_id', $kelasId)->get()->map(function($p) {
            return [
                'raw_id' => $p->id,
                'id' => 'out-'.$p->id,
                'tipe' => 'keluar',
                'judul' => 'Pengeluaran: ' . $p->deskripsi,
                'nominal' => $p->nominal,
                'tanggal' => $p->created_at ? $p->created_at->format('d M Y, H:i') : date('d M Y')
            ];
        });

        // Sesi penarikan diringkas sebagai satu aktivitas, sedangkan detailnya tetap tersimpan.
        $penarikan = PenarikanKas::where('kelas_id', $kelasId)->get()->map(function ($p) {
            return [
                'raw_id' => $p->id,
                'id' => 'withdrawal-'.$p->id,
                'tipe' => 'masuk',
                'judul' => 'Penarikan Kas Minggu-'.$p->minggu_ke,
                'nominal' => $p->total_nominal,
                'tanggal' => $p->dikonfirmasi_pada?->format('d M Y, H:i') ?? $p->created_at?->format('d M Y, H:i'),
            ];
        });

        // Pengeluaran selalu diprioritaskan agar tidak tersisih dari 20 kartu History.
        $transaksi = $pemasukanSiswa->concat($saldoAwal)->concat($penarikan)->concat($pengeluaran);
        $pengeluaranTerbaru = $transaksi->where('tipe', 'keluar')->sortByDesc('raw_id');
        $transaksiLain = $transaksi->where('tipe', '!=', 'keluar')
            ->sortByDesc('raw_id')
            ->take(max(0, 20 - $pengeluaranTerbaru->count()));
        $riwayat = $pengeluaranTerbaru->concat($transaksiLain)->take(20)->values();

        return response()->json(['status' => 'success', 'riwayat' => $riwayat]);
    }

    public function tambahPengeluaran(Request $request)
    {
        $request->validate([
            'kelas_id' => 'required|exists:kelas,id',
            'user_id' => 'required|exists:users,id',
            'deskripsi' => 'required|string|max:255',
            'nominal' => 'required|numeric|min:1'
        ]);

        $pengeluaran = Pengeluaran::create([
            'kelas_id' => $request->kelas_id,
            'deskripsi' => $request->deskripsi,
            'nominal' => (float) $request->nominal
        ]);
        AuditLog::record($request->user_id, 'Mencatat pengeluaran kas: '.$pengeluaran->deskripsi);

        return response()->json([
            'status' => 'success',
            'message' => 'Pengeluaran kas berhasil dicatat!',
            'pengeluaran' => $pengeluaran
        ]);
    }

    public function updateKelas(Request $request)
    {
        $request->validate([
            'kelas_id' => 'required|exists:kelas,id',
            'nama_kelas' => 'required|string|max:255',
            'kode_akses_publik' => 'required|string|max:50|unique:kelas,kode_akses_publik,' . $request->kelas_id,
        ]);

        $kelas = Kelas::find($request->kelas_id);
        $kelas->nama_kelas = $request->nama_kelas;
        $kelas->kode_akses_publik = strtoupper($request->kode_akses_publik);
        $kelas->save();
        AuditLog::record($request->user_id, 'Mengubah pengaturan kelas menjadi '.$kelas->nama_kelas);

        return response()->json([
            'status' => 'success',
            'message' => 'Pengaturan kelas berhasil diperbarui!',
            'kelas' => $kelas
        ]);
    }
}