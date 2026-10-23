<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\HariLiburKas;
use App\Models\Kelas;
use App\Models\PembayaranKas;
use App\Models\PenarikanKas;
use App\Models\PenarikanKasDetail;
use App\Models\Pengeluaran;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use JsonException;

class BackupController extends Controller
{
    private const FORMAT = 'finclass-class-backup';

    private const VERSION = 1;

    public function export(Request $request)
    {
        $data = $request->validate([
            'kelas_id' => 'required|integer|exists:kelas,id',
            'user_id' => 'required|integer|exists:users,id',
        ]);

        $this->ensureTreasurer($data['user_id'], $data['kelas_id']);

        $students = Siswa::where('kelas_id', $data['kelas_id'])->orderBy('id')->get();
        $studentIds = $students->pluck('id');
        $studentAccounts = User::whereIn('id', $students->pluck('user_id')->filter())
            ->get(['id', 'email'])
            ->keyBy('id');
        $withdrawals = PenarikanKas::where('kelas_id', $data['kelas_id'])
            ->with('details')
            ->orderBy('tanggal_penarikan')
            ->get();

        $backup = [
            'format' => self::FORMAT,
            'version' => self::VERSION,
            'created_at' => now()->toIso8601String(),
            'kelas' => Kelas::findOrFail($data['kelas_id'])->only([
                'id',
                'nama_kelas',
                'nominal_mingguan',
                'hari_penarikan',
                'kode_akses_publik',
            ]),
            'siswa' => $students->map(function (Siswa $student) use ($studentAccounts) {
                return array_merge($student->only([
                    'id',
                    'user_id',
                    'nama_siswa',
                    'nomor_wa',
                    'created_at',
                    'updated_at',
                ]), [
                    'user_email' => $studentAccounts->get($student->user_id)?->email,
                ]);
            })->values(),
            'pembayaran_kas' => PembayaranKas::where('kelas_id', $data['kelas_id'])
                ->orWhereIn('siswa_id', $studentIds)
                ->orderBy('id')
                ->get()
                ->map(fn (PembayaranKas $payment) => $payment->only([
                    'id',
                    'siswa_id',
                    'minggu_ke',
                    'bulan',
                    'tahun',
                    'jumlah_bayar',
                    'status',
                    'tanggal_bayar',
                    'created_at',
                    'updated_at',
                ]))->values(),
            'penarikan_kas' => $withdrawals->map(fn (PenarikanKas $withdrawal) => [
                'id' => $withdrawal->id,
                'minggu_ke' => $withdrawal->minggu_ke,
                'tanggal_penarikan' => $withdrawal->tanggal_penarikan?->format('Y-m-d'),
                'total_nominal' => $withdrawal->total_nominal,
                'dikonfirmasi_pada' => $withdrawal->dikonfirmasi_pada?->toIso8601String(),
                'created_at' => $withdrawal->created_at?->toIso8601String(),
                'updated_at' => $withdrawal->updated_at?->toIso8601String(),
                'details' => $withdrawal->details->map(fn (PenarikanKasDetail $detail) => $detail->only([
                    'siswa_id',
                    'nominal',
                    'sudah_bayar',
                    'dibayar_pada',
                    'created_at',
                    'updated_at',
                ]))->values(),
            ])->values(),
            'pengeluaran' => Pengeluaran::where('kelas_id', $data['kelas_id'])
                ->orderBy('id')
                ->get()
                ->map(fn (Pengeluaran $expense) => $expense->only([
                    'deskripsi',
                    'nominal',
                    'foto_nota',
                    'created_at',
                    'updated_at',
                ]))->values(),
            'hari_libur_kas' => HariLiburKas::where('kelas_id', $data['kelas_id'])
                ->orderBy('tanggal')
                ->get()
                ->map(fn (HariLiburKas $holiday) => $holiday->only([
                    'tanggal',
                    'catatan',
                    'created_at',
                    'updated_at',
                ]))->values(),
        ];

        $className = preg_replace('/[^A-Za-z0-9_-]+/', '-', $backup['kelas']['nama_kelas']) ?: 'kelas';
        $filename = 'finclass-backup-'.trim($className, '-').'-'.now()->format('Y-m-d').'.json';

        return response()->streamDownload(
            fn () => print json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $filename,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    public function restore(Request $request)
    {
        $requestData = $request->validate([
            'kelas_id' => 'nullable|integer',
            'user_id' => 'required|integer|exists:users,id',
            'password' => 'required|string',
            'confirmation_text' => ['required', 'string', Rule::in(['PULIHKAN'])],
            'backup_file' => 'required|file|max:51200',
        ]);
        $requestData['kelas_id'] = $requestData['kelas_id'] ?? null;

        $treasurer = User::findOrFail($requestData['user_id']);
        abort_unless($treasurer->role === 'bendahara', 403, 'Hanya bendahara yang dapat memulihkan backup.');
        if ($requestData['kelas_id'] !== null) {
            $this->ensureTreasurer($requestData['user_id'], $requestData['kelas_id']);
        } elseif ($treasurer->kelas_id && Kelas::whereKey($treasurer->kelas_id)->exists()) {
            abort(409, 'Kelas aktif terdeteksi. Muat ulang profil sebelum memulihkan backup.');
        }
        abort_unless(Hash::check($requestData['password'], $treasurer->password), 403, 'Kata sandi bendahara tidak sesuai.');

        $file = $request->file('backup_file');
        if (strtolower($file->getClientOriginalExtension()) !== 'json') {
            throw ValidationException::withMessages([
                'backup_file' => 'File backup harus berformat JSON.',
            ]);
        }

        try {
            $contents = file_get_contents($file->getRealPath());
            if ($contents === false) {
                throw new JsonException('Unable to read backup file.');
            }
            $backup = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'backup_file' => 'Isi file backup bukan JSON yang valid.',
            ]);
        }

        if (! is_array($backup)) {
            throw ValidationException::withMessages([
                'backup_file' => 'Isi file backup harus berupa objek JSON FinClass.',
            ]);
        }

        $validator = Validator::make($backup, $this->backupRules());
        $validated = $validator->validate();
        if ($requestData['kelas_id'] !== null
            && isset($validated['kelas']['id'])
            && (int) $validated['kelas']['id'] !== (int) $requestData['kelas_id']) {
            throw ValidationException::withMessages([
                'backup_file' => 'Backup ini berasal dari kelas yang berbeda. Pulihkan melalui akun bendahara tanpa kelas aktif.',
            ]);
        }
        $this->validateBackupReferences($validated);

        $result = DB::transaction(function () use ($validated, $requestData) {
            $treasurer = User::whereKey($requestData['user_id'])->lockForUpdate()->firstOrFail();
            abort_unless($treasurer->role === 'bendahara', 403, 'Hanya bendahara yang dapat memulihkan backup.');

            if ($requestData['kelas_id'] !== null) {
                $kelas = Kelas::whereKey($requestData['kelas_id'])->lockForUpdate()->firstOrFail();
                $this->ensureTreasurer($treasurer->id, $kelas->id);
            } else {
                abort_if(
                    $treasurer->kelas_id && Kelas::whereKey($treasurer->kelas_id)->exists(),
                    409,
                    'Kelas aktif terdeteksi. Muat ulang profil sebelum memulihkan backup.'
                );
                $kelas = null;
            }
            $restoringExistingClass = $kelas !== null;

            $classAttributes = [
                'nama_kelas' => $validated['kelas']['nama_kelas'],
                'nominal_mingguan' => $validated['kelas']['nominal_mingguan'],
                'hari_penarikan' => $validated['kelas']['hari_penarikan'],
                'kode_akses_publik' => strtoupper($validated['kelas']['kode_akses_publik']),
            ];
            $classCodeCollision = Kelas::where('kode_akses_publik', $classAttributes['kode_akses_publik'])
                ->when($kelas, fn ($query) => $query->where('id', '!=', $kelas->id))
                ->exists();

            if ($classCodeCollision) {
                do {
                    $classAttributes['kode_akses_publik'] = strtoupper(Str::random(6));
                } while (Kelas::where('kode_akses_publik', $classAttributes['kode_akses_publik'])->exists());
            }

            if ($kelas) {
                $classId = $kelas->id;
            } else {
                $kelas = Kelas::create($classAttributes);
                $classId = $kelas->id;
                $treasurer->update(['kelas_id' => $classId]);
            }

            $studentIds = Siswa::where('kelas_id', $classId)->pluck('id');

            PembayaranKas::where('kelas_id', $classId)
                ->orWhereIn('siswa_id', $studentIds)
                ->delete();
            HariLiburKas::where('kelas_id', $classId)->delete();
            Pengeluaran::where('kelas_id', $classId)->delete();
            PenarikanKas::where('kelas_id', $classId)->delete();
            Siswa::where('kelas_id', $classId)->delete();

            $kelas->update($classAttributes);

            $studentIdMap = [];
            foreach ($validated['siswa'] as $student) {
                $linkedUser = null;
                if (isset($student['user_id'], $student['user_email'])) {
                    $linkedUser = User::whereKey($student['user_id'])
                        ->where('email', $student['user_email'])
                        ->where('role', 'siswa')
                        ->first();
                } elseif (isset($student['user_id']) && $restoringExistingClass) {
                    $linkedUser = User::whereKey($student['user_id'])
                        ->where('kelas_id', $classId)
                        ->where('role', 'siswa')
                        ->first();
                } elseif (isset($student['user_id'], $validated['kelas']['id'])
                    && ! Kelas::whereKey($validated['kelas']['id'])->exists()) {
                    $linkedUser = User::whereKey($student['user_id'])
                        ->where('kelas_id', $validated['kelas']['id'])
                        ->where('role', 'siswa')
                        ->first();
                }
                $sourceClassId = $validated['kelas']['id'] ?? null;
                $canRestoreMembership = $linkedUser && (
                    (int) $linkedUser->kelas_id === (int) $classId
                    || $linkedUser->kelas_id === null
                    || (
                        $sourceClassId
                        && (int) $linkedUser->kelas_id === (int) $sourceClassId
                        && ! Kelas::whereKey($sourceClassId)->exists()
                    )
                );
                $linkedUserId = $canRestoreMembership ? $linkedUser->id : null;

                if ($linkedUserId && (int) $linkedUser->kelas_id !== (int) $classId) {
                    $linkedUser->update(['kelas_id' => $classId]);
                }

                $studentIdMap[$student['id']] = DB::table('siswas')->insertGetId([
                    'kelas_id' => $classId,
                    'user_id' => $linkedUserId,
                    'nama_siswa' => $student['nama_siswa'],
                    'nomor_wa' => $student['nomor_wa'] ?? null,
                    'created_at' => $student['created_at'] ?? now(),
                    'updated_at' => $student['updated_at'] ?? now(),
                ]);
            }

            foreach ($validated['pembayaran_kas'] as $payment) {
                DB::table('pembayaran_kas')->insert([
                    'siswa_id' => isset($payment['siswa_id']) ? $studentIdMap[$payment['siswa_id']] : null,
                    'kelas_id' => $classId,
                    'minggu_ke' => $payment['minggu_ke'],
                    'bulan' => $payment['bulan'],
                    'tahun' => $payment['tahun'],
                    'jumlah_bayar' => $payment['jumlah_bayar'],
                    'status' => $payment['status'],
                    'tanggal_bayar' => $payment['tanggal_bayar'] ?? null,
                    'created_at' => $payment['created_at'] ?? now(),
                    'updated_at' => $payment['updated_at'] ?? now(),
                ]);
            }

            foreach ($validated['penarikan_kas'] as $withdrawal) {
                $withdrawalId = DB::table('penarikan_kas')->insertGetId([
                    'kelas_id' => $classId,
                    'minggu_ke' => $withdrawal['minggu_ke'],
                    'tanggal_penarikan' => $withdrawal['tanggal_penarikan'] ?? null,
                    'total_nominal' => $withdrawal['total_nominal'],
                    'dikonfirmasi_pada' => $withdrawal['dikonfirmasi_pada'],
                    'created_at' => $withdrawal['created_at'] ?? now(),
                    'updated_at' => $withdrawal['updated_at'] ?? now(),
                ]);

                foreach ($withdrawal['details'] as $detail) {
                    DB::table('penarikan_kas_detail')->insert([
                        'penarikan_kas_id' => $withdrawalId,
                        'siswa_id' => $studentIdMap[$detail['siswa_id']],
                        'nominal' => $detail['nominal'],
                        'sudah_bayar' => $detail['sudah_bayar'],
                        'dibayar_pada' => $detail['dibayar_pada'] ?? null,
                        'created_at' => $detail['created_at'] ?? now(),
                        'updated_at' => $detail['updated_at'] ?? now(),
                    ]);
                }
            }

            foreach ($validated['pengeluaran'] as $expense) {
                DB::table('pengeluarans')->insert([
                    'kelas_id' => $classId,
                    'deskripsi' => $expense['deskripsi'],
                    'nominal' => $expense['nominal'],
                    'foto_nota' => $expense['foto_nota'] ?? null,
                    'created_at' => $expense['created_at'] ?? now(),
                    'updated_at' => $expense['updated_at'] ?? now(),
                ]);
            }

            foreach ($validated['hari_libur_kas'] as $holiday) {
                DB::table('hari_libur_kas')->insert([
                    'kelas_id' => $classId,
                    'user_id' => $requestData['user_id'],
                    'tanggal' => $holiday['tanggal'],
                    'catatan' => $holiday['catatan'] ?? null,
                    'created_at' => $holiday['created_at'] ?? now(),
                    'updated_at' => $holiday['updated_at'] ?? now(),
                ]);
            }

            AuditLog::record($requestData['user_id'], 'Memulihkan backup data kelas '.$kelas->nama_kelas);

            return [
                'kelas' => $kelas->fresh(),
                'user' => $treasurer->fresh(),
                'kode_akses_diperbarui' => $classCodeCollision,
            ];
        });

        return response()->json([
            'status' => 'success',
            'message' => $result['kode_akses_diperbarui']
                ? 'Backup berhasil dipulihkan. Kode akses kelas dibuat ulang karena kode pada backup sudah digunakan.'
                : 'Backup berhasil dipulihkan. Data siswa, kas, transaksi, dan pengaturan kelas telah diperbarui.',
            'kelas' => $result['kelas'],
            'user' => $result['user'],
        ]);
    }

    private function backupRules(): array
    {
        $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

        return [
            'format' => ['required', 'string', Rule::in([self::FORMAT])],
            'version' => ['required', 'integer', Rule::in([self::VERSION])],
            'created_at' => ['nullable', 'date'],
            'kelas' => ['required', 'array'],
            'kelas.id' => ['nullable', 'integer', 'min:1'],
            'kelas.nama_kelas' => ['required', 'string', 'max:255'],
            'kelas.nominal_mingguan' => ['required', 'numeric', 'min:1', 'max:99999999.99'],
            'kelas.hari_penarikan' => ['required', Rule::in($days)],
            'kelas.kode_akses_publik' => ['required', 'string', 'max:50'],
            'siswa' => ['present', 'array'],
            'siswa.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'siswa.*.user_id' => ['nullable', 'integer', 'min:1'],
            'siswa.*.user_email' => ['nullable', 'email', 'max:255'],
            'siswa.*.nama_siswa' => ['required', 'string', 'max:255'],
            'siswa.*.nomor_wa' => ['nullable', 'string', 'max:255'],
            'siswa.*.created_at' => ['nullable', 'date'],
            'siswa.*.updated_at' => ['nullable', 'date'],
            'pembayaran_kas' => ['present', 'array'],
            'pembayaran_kas.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'pembayaran_kas.*.siswa_id' => ['nullable', 'integer', 'min:1'],
            'pembayaran_kas.*.minggu_ke' => ['required', 'integer'],
            'pembayaran_kas.*.bulan' => ['required', 'string', 'max:255'],
            'pembayaran_kas.*.tahun' => ['required', 'integer'],
            'pembayaran_kas.*.jumlah_bayar' => ['required', 'numeric', 'max:99999999.99'],
            'pembayaran_kas.*.status' => ['required', Rule::in(['lunas', 'tunggakan'])],
            'pembayaran_kas.*.tanggal_bayar' => ['nullable', 'date'],
            'pembayaran_kas.*.created_at' => ['nullable', 'date'],
            'pembayaran_kas.*.updated_at' => ['nullable', 'date'],
            'penarikan_kas' => ['present', 'array'],
            'penarikan_kas.*.id' => ['required', 'integer', 'min:1', 'distinct'],
            'penarikan_kas.*.minggu_ke' => ['required', 'integer', 'min:0'],
            'penarikan_kas.*.tanggal_penarikan' => ['nullable', 'date'],
            'penarikan_kas.*.total_nominal' => ['required', 'numeric', 'max:9999999999.99'],
            'penarikan_kas.*.dikonfirmasi_pada' => ['required', 'date'],
            'penarikan_kas.*.created_at' => ['nullable', 'date'],
            'penarikan_kas.*.updated_at' => ['nullable', 'date'],
            'penarikan_kas.*.details' => ['present', 'array'],
            'penarikan_kas.*.details.*.siswa_id' => ['required', 'integer', 'min:1'],
            'penarikan_kas.*.details.*.nominal' => ['required', 'numeric', 'max:99999999.99'],
            'penarikan_kas.*.details.*.sudah_bayar' => ['required', 'boolean'],
            'penarikan_kas.*.details.*.dibayar_pada' => ['nullable', 'date'],
            'penarikan_kas.*.details.*.created_at' => ['nullable', 'date'],
            'penarikan_kas.*.details.*.updated_at' => ['nullable', 'date'],
            'pengeluaran' => ['present', 'array'],
            'pengeluaran.*.deskripsi' => ['required', 'string', 'max:255'],
            'pengeluaran.*.nominal' => ['required', 'numeric', 'max:99999999.99'],
            'pengeluaran.*.foto_nota' => ['nullable', 'string', 'max:255'],
            'pengeluaran.*.created_at' => ['nullable', 'date'],
            'pengeluaran.*.updated_at' => ['nullable', 'date'],
            'hari_libur_kas' => ['present', 'array'],
            'hari_libur_kas.*.tanggal' => ['required', 'date'],
            'hari_libur_kas.*.catatan' => ['nullable', 'string'],
            'hari_libur_kas.*.created_at' => ['nullable', 'date'],
            'hari_libur_kas.*.updated_at' => ['nullable', 'date'],
        ];
    }

    private function validateBackupReferences(array $backup): void
    {
        $studentIds = collect($backup['siswa'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        $withdrawalIds = collect($backup['penarikan_kas'])->pluck('id')->map(fn ($id) => (int) $id)->all();

        foreach ($backup['pembayaran_kas'] as $payment) {
            if ($payment['siswa_id'] !== null && ! in_array((int) $payment['siswa_id'], $studentIds, true)) {
                throw ValidationException::withMessages([
                    'backup_file' => 'Backup berisi pembayaran yang merujuk ke siswa yang tidak tersedia.',
                ]);
            }
        }

        $withdrawalDates = collect($backup['penarikan_kas'])
            ->pluck('tanggal_penarikan')
            ->filter()
            ->all();
        if (count($withdrawalDates) !== count(array_unique($withdrawalDates))) {
            throw ValidationException::withMessages([
                'backup_file' => 'Backup berisi tanggal penarikan yang duplikat.',
            ]);
        }

        foreach ($backup['penarikan_kas'] as $withdrawal) {
            $detailStudentIds = [];
            foreach ($withdrawal['details'] as $detail) {
                $detailStudentId = (int) $detail['siswa_id'];
                if (! in_array($detailStudentId, $studentIds, true)) {
                    throw ValidationException::withMessages([
                        'backup_file' => 'Backup berisi detail kas yang merujuk ke siswa yang tidak tersedia.',
                    ]);
                }
                if (in_array($detailStudentId, $detailStudentIds, true)) {
                    throw ValidationException::withMessages([
                        'backup_file' => 'Backup berisi checklist pembayaran siswa yang duplikat.',
                    ]);
                }
                $detailStudentIds[] = $detailStudentId;
            }
        }

        if (count($withdrawalIds) !== count(array_unique($withdrawalIds))) {
            throw ValidationException::withMessages([
                'backup_file' => 'Backup berisi identitas penarikan yang duplikat.',
            ]);
        }

        $holidayDates = collect($backup['hari_libur_kas'])->pluck('tanggal')->all();
        if (count($holidayDates) !== count(array_unique($holidayDates))) {
            throw ValidationException::withMessages([
                'backup_file' => 'Backup berisi tanggal libur kas yang duplikat.',
            ]);
        }
    }

    private function ensureTreasurer(int $userId, int $kelasId): void
    {
        abort_unless(
            User::whereKey($userId)->where('kelas_id', $kelasId)->where('role', 'bendahara')->exists(),
            403,
            'Hanya bendahara kelas yang dapat mengelola backup.'
        );
    }
}
