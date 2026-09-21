<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\PenarikanKas;
use App\Models\PenarikanKasDetail;
use App\Models\Siswa;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Support\Carbon;

class PenarikanController extends Controller
{
    // Mengambil daftar sesi penarikan yang sudah dikonfirmasi bendahara.
    public function index(Request $request)
    {
        $request->validate(['kelas_id' => 'required|exists:kelas,id']);

        $sessions = PenarikanKas::where('kelas_id', $request->kelas_id)->with('details')->get();
        $months = $sessions->groupBy(function ($session) {
            $date = $session->tanggal_penarikan ?? $session->dikonfirmasi_pada;
            return $date?->format('Y-m');
        })->filter()->sortKeysDesc()->take(20)->map(function ($items, $key) {
            [$year, $month] = array_map('intval', explode('-', $key));
            $monthNames = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
            return [
                'id' => $key,
                'bulan' => $month,
                'tahun' => $year,
                'judul' => 'Buku Kas ' . ($monthNames[$month] ?? $month) . ' ' . $year,
                'total_nominal' => (float) $items->sum('total_nominal'),
                'jumlah_siswa' => (int) $items->sum(fn ($item) => $item->details->where('sudah_bayar', true)->count()),
                'jumlah_tanggal' => $items->count(),
            ];
        })->values();

        return response()->json(['status' => 'success', 'data' => $months]);
    }

    // Membuat buku kas satu bulan beserta semua kolom hari penarikan.
    public function createBook(Request $request)
    {
        $data = $request->validate([
            'kelas_id' => 'required|exists:kelas,id',
            'bulan' => 'required|integer|between:1,12',
            'tahun' => 'required|integer|min:2000|max:2100',
            'user_id' => 'required|exists:users,id',
        ]);
        abort_unless(User::whereKey($data['user_id'])->where('kelas_id', $data['kelas_id'])->where('role', 'bendahara')->exists(), 403, 'Hanya bendahara kelas yang dapat membuat buku kas.');
        $kelas = Kelas::findOrFail($data['kelas_id']);
        $dates = $this->datesForMonth($kelas->hari_penarikan, $data['bulan'], $data['tahun']);
        $students = Siswa::where('kelas_id', $kelas->id)->get();

        $alreadyExists = PenarikanKas::where('kelas_id', $kelas->id)
            ->whereBetween('tanggal_penarikan', [$dates[0]->toDateString(), end($dates)->toDateString()])
            ->exists();

        DB::transaction(function () use ($dates, $students, $kelas) {
            foreach ($dates as $date) {
                $session = PenarikanKas::firstOrCreate(
                    ['kelas_id' => $kelas->id, 'tanggal_penarikan' => $date->toDateString()],
                    ['minggu_ke' => 0, 'dikonfirmasi_pada' => $date->endOfDay(), 'total_nominal' => 0]
                );
                foreach ($students as $student) {
                    PenarikanKasDetail::firstOrCreate(
                        ['penarikan_kas_id' => $session->id, 'siswa_id' => $student->id],
                        ['nominal' => 0, 'sudah_bayar' => false]
                    );
                }
            }
        });
        AuditLog::record($data['user_id'], 'Membuat atau membuka Buku Kas '.$data['tahun'].'-'.$data['bulan']);

        return response()->json([
            'status' => 'success',
            'already_exists' => $alreadyExists,
            'message' => $alreadyExists ? 'Buku kas bulan tersebut sudah tersedia.' : 'Buku kas berhasil dibuat.',
        ]);
    }

    // Menghapus satu buku bulanan beserta seluruh detail checklist-nya.
    public function destroyBook(Request $request, int $tahun, int $bulan)
    {
        $data = $request->validate([
            'kelas_id' => 'required|exists:kelas,id',
            'user_id' => 'required|exists:users,id',
        ]);
        abort_unless(User::whereKey($data['user_id'])->where('kelas_id', $data['kelas_id'])->where('role', 'bendahara')->exists(), 403, 'Hanya bendahara kelas yang dapat menghapus buku kas.');

        $kelas = Kelas::findOrFail($data['kelas_id']);
        $dates = $this->datesForMonth($kelas->hari_penarikan, $bulan, $tahun);
        DB::transaction(function () use ($data, $dates) {
            if (!$dates) return;
            PenarikanKas::where('kelas_id', $data['kelas_id'])
                ->whereBetween('tanggal_penarikan', [$dates[0]->toDateString(), end($dates)->toDateString()])
                ->delete();
        });
        AuditLog::record($data['user_id'], 'Menghapus Buku Kas '.$tahun.'-'.$bulan);

        return response()->json(['status' => 'success', 'message' => 'Buku kas berhasil dihapus.']);
    }

    // Membuat daftar tanggal penarikan dalam bulan sesuai hari yang diatur kelas.
    public function form(Request $request)
    {
        $data = $request->validate([
            'kelas_id' => 'required|exists:kelas,id',
            'bulan' => 'required|integer|between:1,12',
            'tahun' => 'required|integer|min:2000|max:2100',
            'tanggal' => 'nullable|date',
        ]);

        $kelas = Kelas::findOrFail($data['kelas_id']);
        $dates = $this->datesForMonth($kelas->hari_penarikan, $data['bulan'], $data['tahun']);
        $monthStart = Carbon::create($data['tahun'], $data['bulan'], 1)->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();
        $sessions = PenarikanKas::where('kelas_id', $kelas->id)
            ->with('details')->get()
            ->filter(function ($session) use ($monthStart, $monthEnd) {
                $date = $session->tanggal_penarikan ?? $session->dikonfirmasi_pada;
                return $date && $date->betweenIncluded($monthStart, $monthEnd);
            })->sortBy('dikonfirmasi_pada')->values();
        $dateValues = collect($dates)->map(fn ($date) => $date->toDateString())
            ->merge($sessions->map(fn ($session) => $session->tanggal_penarikan?->format('Y-m-d') ?? $session->dikonfirmasi_pada?->format('Y-m-d')))
            ->filter()->unique()->sort()->values();
        $activeDate = $dateValues->contains($data['tanggal'] ?? null)
            ? $data['tanggal']
            : ($dateValues->first() ?? null);
        // Pertahankan urutan anggota sesuai urutan saat ditambahkan di Kelola Anggota.
        $siswa = Siswa::where('kelas_id', $kelas->id)->orderBy('id')->get();

        $previousDetails = PenarikanKasDetail::whereHas('penarikanKas', function ($query) use ($kelas) {
            $query->where('kelas_id', $kelas->id)
                ->whereDate('tanggal_penarikan', '<=', now()->toDateString());
        })->get()->groupBy('siswa_id');

        $students = $siswa->map(function ($student) use ($previousDetails, $sessions, $kelas, $activeDate) {
            $unpaid = $previousDetails->get($student->id, collect())->where('sudah_bayar', false)->count();
            $weeks = $sessions->mapWithKeys(function ($session) use ($student) {
                $detail = $session->details->firstWhere('siswa_id', $student->id);
                $date = $session->tanggal_penarikan?->format('Y-m-d') ?? $session->dikonfirmasi_pada?->format('Y-m-d');
                return [$date => (bool) ($detail?->sudah_bayar ?? false)];
            });

            return [
                'id' => $student->id,
                'nama_siswa' => $student->nama_siswa,
                'sudah_bayar' => (bool) $weeks->get($activeDate, false),
                'tunggakan' => $unpaid,
                'nominal' => (float) $kelas->nominal_mingguan,
                'tanggal' => $weeks,
            ];
        });

        return response()->json([
            'status' => 'success',
            'kelas' => $kelas,
            // Buku kas menampilkan maksimal 15 kolom tanggal.
            'tanggal_kolom' => $dateValues->take(15)->values(),
            'tanggal_aktif' => $activeDate,
            'siswas' => $students,
            'sudah_dikonfirmasi' => false,
        ]);
    }

    // Menyimpan checklist seluruh siswa dalam satu transaksi database.
    public function store(Request $request)
    {
        $validated = $request->validate([
            'kelas_id' => 'required|exists:kelas,id',
            'tanggal_penarikan' => 'required|date',
            'pembayaran' => 'required|array|min:1',
            'pembayaran.*.siswa_id' => [
                'required',
                Rule::exists('siswas', 'id')->where('kelas_id', $request->kelas_id),
            ],
            'pembayaran.*.sudah_bayar' => 'required|boolean',
        ]);

        $kelas = Kelas::findOrFail($validated['kelas_id']);
        $tanggal = Carbon::parse($validated['tanggal_penarikan']);
        $allowedDates = collect($this->datesForMonth($kelas->hari_penarikan, $tanggal->month, $tanggal->year))
            ->contains(fn ($date) => $date->toDateString() === $tanggal->toDateString());
        if (!$allowedDates) {
            return response()->json(['status' => 'error', 'message' => "Tanggal harus jatuh pada hari penarikan kelas ({$kelas->hari_penarikan})."], 422);
        }

        $session = DB::transaction(function () use ($validated, $kelas) {
            $tanggal = Carbon::parse($validated['tanggal_penarikan'])->startOfDay();
            $lastWeek = PenarikanKas::where('kelas_id', $validated['kelas_id'])
                ->lockForUpdate()
                ->max('minggu_ke');
            $session = PenarikanKas::updateOrCreate(
                ['kelas_id' => $validated['kelas_id'], 'tanggal_penarikan' => $tanggal->toDateString()],
                [
                    'minggu_ke' => ((int) $lastWeek) + 1,
                    'dikonfirmasi_pada' => $tanggal->endOfDay(),
                ]
            );

            $total = 0;
            foreach ($validated['pembayaran'] as $payment) {
                $paid = (bool) $payment['sudah_bayar'];
                $nominal = $paid ? (float) $kelas->nominal_mingguan : 0;
                $total += $nominal;

                PenarikanKasDetail::updateOrCreate(
                    ['penarikan_kas_id' => $session->id, 'siswa_id' => $payment['siswa_id']],
                    [
                        'nominal' => $nominal,
                        'sudah_bayar' => $paid,
                        'dibayar_pada' => $paid ? now() : null,
                    ]
                );
            }

            $session->update(['total_nominal' => $total]);
            return $session->load('details');
        });

        return response()->json([
            'status' => 'success',
            'message' => 'Penarikan kas berhasil dikonfirmasi.',
            'data' => $session,
        ], 201);
    }

    // Mengubah status pembayaran pada minggu lama dari tabel riwayat.
    public function updateDetail(Request $request, string $tanggal, int $siswaId)
    {
        $data = $request->validate([
            'kelas_id' => 'required|exists:kelas,id',
            'user_id' => 'required|exists:users,id',
            'sudah_bayar' => 'required|boolean',
        ]);
        abort_unless(User::whereKey($data['user_id'])->where('kelas_id', $data['kelas_id'])->where('role', 'bendahara')->exists(), 403, 'Hanya bendahara kelas yang dapat mengubah buku kas.');

        $result = DB::transaction(function () use ($data, $tanggal, $siswaId) {
            $kelas = Kelas::findOrFail($data['kelas_id']);
            $siswa = Siswa::where('kelas_id', $kelas->id)->findOrFail($siswaId);
            $session = PenarikanKas::where('kelas_id', $kelas->id)
                ->whereDate('tanggal_penarikan', $tanggal)
                ->lockForUpdate()
                ->first();

            // Kolom tanggal bisa berasal dari jadwal bulan, jadi detail dibuat saat pertama kali diklik.
            if (!$session) {
                $session = PenarikanKas::create([
                    'kelas_id' => $kelas->id,
                    'tanggal_penarikan' => Carbon::parse($tanggal)->toDateString(),
                    'minggu_ke' => 0,
                    'dikonfirmasi_pada' => Carbon::parse($tanggal)->endOfDay(),
                    'total_nominal' => 0,
                ]);
            }

            $detail = PenarikanKasDetail::firstOrCreate(
                ['penarikan_kas_id' => $session->id, 'siswa_id' => $siswa->id],
                ['nominal' => 0, 'sudah_bayar' => false]
            );
            $paid = (bool) $data['sudah_bayar'];
            $detail->update([
                'sudah_bayar' => $paid,
                'nominal' => $paid ? $kelas->nominal_mingguan : 0,
                'dibayar_pada' => $paid ? now() : null,
            ]);
            $session->update(['total_nominal' => $session->details()->where('sudah_bayar', true)->sum('nominal')]);
            AuditLog::record($data['user_id'], ($paid ? 'Mencentang' : 'Membatalkan centang').' pembayaran siswa pada '.$tanggal);

            return compact('session', 'detail');
        });

        return response()->json(['status' => 'success', 'data' => $result]);
    }

    private function datesForMonth(string $dayName, int $month, int $year): array
    {
        $days = [
            'Minggu' => Carbon::SUNDAY, 'Senin' => Carbon::MONDAY, 'Selasa' => Carbon::TUESDAY,
            'Rabu' => Carbon::WEDNESDAY, 'Kamis' => Carbon::THURSDAY, 'Jumat' => Carbon::FRIDAY,
            'Sabtu' => Carbon::SATURDAY,
        ];
        $date = Carbon::create($year, $month, 1)->startOfDay();
        $dates = [];
        while ($date->month === $month) {
            if ($date->dayOfWeek === ($days[$dayName] ?? Carbon::WEDNESDAY)) $dates[] = $date->copy();
            $date->addDay();
        }
        return $dates;
    }
}
