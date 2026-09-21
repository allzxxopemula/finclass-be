<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    // Mengambil aktivitas akun terbaru untuk timeline History.
    public function index(Request $request)
    {
        $data = $request->validate(['user_id' => 'required|exists:users,id']);
        $logs = AuditLog::where('user_id', $data['user_id'])->latest()->limit(100)->get()->map(fn ($log) => [
            'raw_id' => $log->id,
            'id' => 'activity-'.$log->id,
            'tipe' => 'aktivitas',
            'judul' => $log->aksi,
            'nominal' => null,
            'tanggal' => $log->created_at?->format('d M Y, H:i'),
        ]);
        return response()->json(['status' => 'success', 'aktivitas' => $logs]);
    }
}