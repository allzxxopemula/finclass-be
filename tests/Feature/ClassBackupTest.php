<?php

use App\Models\HariLiburKas;
use App\Models\Kelas;
use App\Models\PembayaranKas;
use App\Models\PenarikanKas;
use App\Models\PenarikanKasDetail;
use App\Models\Pengeluaran;
use App\Models\Siswa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

function createTreasurerWithClass(array $classAttributes = []): array
{
    $kelas = Kelas::create(array_merge([
        'nama_kelas' => 'XI RPL 1',
        'nominal_mingguan' => 2000,
        'hari_penarikan' => 'Rabu',
        'kode_akses_publik' => 'RPL001',
    ], $classAttributes));

    $user = User::factory()->create([
        'role' => 'bendahara',
        'kelas_id' => $kelas->id,
    ]);

    return [$user, $kelas];
}

it('exports only the treasurers class data in a versioned backup', function () {
    [$user, $kelas] = createTreasurerWithClass();
    $studentAccount = User::factory()->create([
        'role' => 'siswa',
        'kelas_id' => $kelas->id,
    ]);
    $student = Siswa::create([
        'kelas_id' => $kelas->id,
        'user_id' => $studentAccount->id,
        'nama_siswa' => 'Ayu',
        'nomor_wa' => '081234',
    ]);
    PembayaranKas::create([
        'siswa_id' => $student->id,
        'kelas_id' => $kelas->id,
        'minggu_ke' => 1,
        'bulan' => 'Oktober',
        'tahun' => 2026,
        'jumlah_bayar' => 2000,
        'status' => 'lunas',
    ]);
    $withdrawal = PenarikanKas::create([
        'kelas_id' => $kelas->id,
        'minggu_ke' => 1,
        'tanggal_penarikan' => '2026-10-21',
        'total_nominal' => 2000,
        'dikonfirmasi_pada' => '2026-10-21 23:59:59',
    ]);
    PenarikanKasDetail::create([
        'penarikan_kas_id' => $withdrawal->id,
        'siswa_id' => $student->id,
        'nominal' => 2000,
        'sudah_bayar' => true,
    ]);
    Pengeluaran::create(['kelas_id' => $kelas->id, 'deskripsi' => 'Alat tulis', 'nominal' => 500]);
    HariLiburKas::create(['kelas_id' => $kelas->id, 'user_id' => $user->id, 'tanggal' => '2026-10-28']);

    $response = $this->getJson("/api/backup/export?kelas_id={$kelas->id}&user_id={$user->id}");

    $response->assertOk();
    $backup = json_decode($response->streamedContent(), true, 512, JSON_THROW_ON_ERROR);
    expect($backup['format'])->toBe('finclass-class-backup')
        ->and($backup['version'])->toBe(1)
        ->and($backup['kelas']['id'])->toBe($kelas->id)
        ->and($backup['siswa'])->toHaveCount(1)
        ->and($backup['siswa'][0]['user_email'])->toBe($studentAccount->email)
        ->and($backup['pembayaran_kas'])->toHaveCount(1)
        ->and($backup['penarikan_kas'][0]['details'])->toHaveCount(1)
        ->and($backup['pengeluaran'])->toHaveCount(1)
        ->and($backup['hari_libur_kas'])->toHaveCount(1);
});

it('restores a validated backup atomically without changing other classes or accounts', function () {
    [$user, $kelas] = createTreasurerWithClass();
    $oldStudent = Siswa::create(['kelas_id' => $kelas->id, 'nama_siswa' => 'Data lama']);
    PembayaranKas::create([
        'siswa_id' => $oldStudent->id,
        'kelas_id' => $kelas->id,
        'minggu_ke' => 1,
        'bulan' => 'Lama',
        'tahun' => 2025,
        'jumlah_bayar' => 1000,
        'status' => 'lunas',
    ]);

    $otherClass = Kelas::create([
        'nama_kelas' => 'XI TKJ',
        'nominal_mingguan' => 3000,
        'hari_penarikan' => 'Kamis',
        'kode_akses_publik' => 'TKJ001',
    ]);
    $otherStudent = Siswa::create(['kelas_id' => $otherClass->id, 'nama_siswa' => 'Tetap ada']);

    $backup = [
        'format' => 'finclass-class-backup',
        'version' => 1,
        'created_at' => now()->toIso8601String(),
        'kelas' => [
            'nama_kelas' => 'XI RPL Pulih',
            'nominal_mingguan' => 5000,
            'hari_penarikan' => 'Jumat',
            'kode_akses_publik' => 'RPL001',
        ],
        'siswa' => [[
            'id' => 9001,
            'user_id' => null,
            'nama_siswa' => 'Ayu',
            'nomor_wa' => '081234',
        ]],
        'pembayaran_kas' => [[
            'id' => 9002,
            'siswa_id' => 9001,
            'minggu_ke' => 1,
            'bulan' => 'Oktober',
            'tahun' => 2026,
            'jumlah_bayar' => 5000,
            'status' => 'lunas',
        ]],
        'penarikan_kas' => [[
            'id' => 9003,
            'minggu_ke' => 1,
            'tanggal_penarikan' => '2026-10-23',
            'total_nominal' => 5000,
            'dikonfirmasi_pada' => '2026-10-23 23:59:59',
            'details' => [[
                'siswa_id' => 9001,
                'nominal' => 5000,
                'sudah_bayar' => true,
            ]],
        ]],
        'pengeluaran' => [['deskripsi' => 'Buku', 'nominal' => 1000, 'foto_nota' => null]],
        'hari_libur_kas' => [['tanggal' => '2026-10-30', 'catatan' => 'Libur']],
    ];

    $response = $this->post('/api/backup/restore', [
        'kelas_id' => $kelas->id,
        'user_id' => $user->id,
        'password' => 'password',
        'confirmation_text' => 'PULIHKAN',
        'backup_file' => UploadedFile::fake()->createWithContent('finclass-backup.json', json_encode($backup)),
    ]);

    $response->assertOk()->assertJsonPath('status', 'success');
    $restoredStudent = Siswa::where('kelas_id', $kelas->id)->sole();
    expect($restoredStudent->nama_siswa)->toBe('Ayu')
        ->and((float) PembayaranKas::where('kelas_id', $kelas->id)->sole()->jumlah_bayar)->toBe(5000.0)
        ->and(PenarikanKas::where('kelas_id', $kelas->id)->sole()->details()->sole()->siswa_id)->toBe($restoredStudent->id)
        ->and(Pengeluaran::where('kelas_id', $kelas->id)->sole()->deskripsi)->toBe('Buku')
        ->and(HariLiburKas::where('kelas_id', $kelas->id)->sole()->user_id)->toBe($user->id)
        ->and($kelas->fresh()->nama_kelas)->toBe('XI RPL Pulih')
        ->and(Siswa::whereKey($oldStudent->id)->exists())->toBeFalse()
        ->and(Siswa::whereKey($otherStudent->id)->exists())->toBeTrue()
        ->and(User::whereKey($user->id)->exists())->toBeTrue();
});

it('recreates a missing class for a bendahara account without an active class', function () {
    [$oldTreasurer, $deletedClass] = createTreasurerWithClass();
    $studentAccount = User::factory()->create([
        'role' => 'siswa',
        'kelas_id' => $deletedClass->id,
    ]);
    Siswa::create([
        'kelas_id' => $deletedClass->id,
        'user_id' => $studentAccount->id,
        'nama_siswa' => 'Siswa Pulih',
        'nomor_wa' => '081234',
    ]);
    $backup = [
        'format' => 'finclass-class-backup',
        'version' => 1,
        'created_at' => now()->toIso8601String(),
        'kelas' => [
            'id' => $deletedClass->id,
            'nama_kelas' => 'XI RPL Dipulihkan',
            'nominal_mingguan' => 4500,
            'hari_penarikan' => 'Jumat',
            'kode_akses_publik' => 'RPL001',
        ],
        'siswa' => [[
            'id' => 7001,
            'user_id' => $studentAccount->id,
            'nama_siswa' => 'Siswa Pulih',
            'nomor_wa' => '081234',
        ]],
        'pembayaran_kas' => [],
        'penarikan_kas' => [[
            'id' => 7002,
            'minggu_ke' => 1,
            'tanggal_penarikan' => '2026-10-23',
            'total_nominal' => 4500,
            'dikonfirmasi_pada' => '2026-10-23 23:59:59',
            'details' => [[
                'siswa_id' => 7001,
                'nominal' => 4500,
                'sudah_bayar' => true,
            ]],
        ]],
        'pengeluaran' => [],
        'hari_libur_kas' => [],
    ];
    $deletedClass->delete();

    $newTreasurer = User::factory()->create([
        'role' => 'bendahara',
        'kelas_id' => null,
    ]);

    $response = $this->post('/api/backup/restore', [
        'user_id' => $newTreasurer->id,
        'password' => 'password',
        'confirmation_text' => 'PULIHKAN',
        'backup_file' => UploadedFile::fake()->createWithContent('class-recovery.json', json_encode($backup)),
    ], ['Accept' => 'application/json']);

    $response->assertOk()->assertJsonPath('status', 'success');
    $recoveredClass = Kelas::where('nama_kelas', 'XI RPL Dipulihkan')->sole();
    $recoveredStudent = Siswa::where('kelas_id', $recoveredClass->id)->sole();
    expect($recoveredClass->id)->not->toBe($deletedClass->id)
        ->and((int) $newTreasurer->fresh()->kelas_id)->toBe($recoveredClass->id)
        ->and($recoveredStudent->nama_siswa)->toBe('Siswa Pulih')
        ->and($recoveredStudent->user_id)->toBe($studentAccount->id)
        ->and((int) $studentAccount->fresh()->kelas_id)->toBe($recoveredClass->id)
        ->and((float) PenarikanKasDetail::where('siswa_id', $recoveredStudent->id)->sole()->nominal)->toBe(4500.0)
        ->and($oldTreasurer->fresh()->kelas_id)->toBe($deletedClass->id);
});

it('rejects invalid backups before modifying existing class data', function () {
    [$user, $kelas] = createTreasurerWithClass();
    $student = Siswa::create(['kelas_id' => $kelas->id, 'nama_siswa' => 'Jangan dihapus']);

    $response = $this->post('/api/backup/restore', [
        'kelas_id' => $kelas->id,
        'user_id' => $user->id,
        'password' => 'password',
        'confirmation_text' => 'PULIHKAN',
        'backup_file' => UploadedFile::fake()->createWithContent('invalid.json', json_encode([
            'format' => 'not-a-finclass-backup',
        ])),
    ], ['Accept' => 'application/json']);

    $response->assertUnprocessable();
    $this->assertDatabaseHas('siswas', ['id' => $student->id, 'nama_siswa' => 'Jangan dihapus']);
});

it('rejects a valid backup that belongs to a different class', function () {
    [$user, $kelas] = createTreasurerWithClass();
    $otherClass = Kelas::create([
        'nama_kelas' => 'XI TKJ',
        'nominal_mingguan' => 3000,
        'hari_penarikan' => 'Kamis',
        'kode_akses_publik' => 'TKJ001',
    ]);
    $student = Siswa::create(['kelas_id' => $kelas->id, 'nama_siswa' => 'Data tetap aman']);

    $backup = [
        'format' => 'finclass-class-backup',
        'version' => 1,
        'kelas' => [
            'id' => $otherClass->id,
            'nama_kelas' => 'Kelas lain',
            'nominal_mingguan' => 3000,
            'hari_penarikan' => 'Kamis',
            'kode_akses_publik' => 'RPL001',
        ],
        'siswa' => [],
        'pembayaran_kas' => [],
        'penarikan_kas' => [],
        'pengeluaran' => [],
        'hari_libur_kas' => [],
    ];

    $response = $this->post('/api/backup/restore', [
        'kelas_id' => $kelas->id,
        'user_id' => $user->id,
        'password' => 'password',
        'confirmation_text' => 'PULIHKAN',
        'backup_file' => UploadedFile::fake()->createWithContent('other-class.json', json_encode($backup)),
    ], ['Accept' => 'application/json']);

    $response->assertUnprocessable();
    $this->assertDatabaseHas('siswas', ['id' => $student->id, 'nama_siswa' => 'Data tetap aman']);
});

it('does not allow non-treasurer accounts to export or restore backups', function () {
    [$treasurer, $kelas] = createTreasurerWithClass();
    $student = User::factory()->create(['role' => 'siswa', 'kelas_id' => $kelas->id]);

    $this->getJson("/api/backup/export?kelas_id={$kelas->id}&user_id={$student->id}")->assertForbidden();
    $this->post('/api/backup/restore', [
        'kelas_id' => $kelas->id,
        'user_id' => $student->id,
        'password' => 'password',
        'confirmation_text' => 'PULIHKAN',
        'backup_file' => UploadedFile::fake()->createWithContent('backup.json', '{}'),
    ], ['Accept' => 'application/json'])->assertForbidden();

    expect($treasurer->fresh()->kelas_id)->toBe($kelas->id);
});

it('does not restore data when the treasurers password is incorrect', function () {
    [$user, $kelas] = createTreasurerWithClass();
    $student = Siswa::create(['kelas_id' => $kelas->id, 'nama_siswa' => 'Data tetap aman']);

    $response = $this->post('/api/backup/restore', [
        'kelas_id' => $kelas->id,
        'user_id' => $user->id,
        'password' => 'wrong-password',
        'confirmation_text' => 'PULIHKAN',
        'backup_file' => UploadedFile::fake()->createWithContent('backup.json', '{}'),
    ], ['Accept' => 'application/json']);

    $response->assertForbidden();
    $this->assertDatabaseHas('siswas', ['id' => $student->id, 'nama_siswa' => 'Data tetap aman']);
});
