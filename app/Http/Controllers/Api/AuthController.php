<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Models\AuditLog;

class AuthController extends Controller
{
    // Memvalidasi kredensial dan mengembalikan user untuk disimpan frontend.
    public function login(Request $request)
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required']);
        $user = User::where('email', $data['email'])->first();

        if (!$user || !Hash::check($data['password'], $user->password)) {
            return response()->json(['status' => 'error', 'message' => 'Email atau password salah!'], 401);
        }

        return response()->json(['status' => 'success', 'message' => 'Login berhasil!', 'user' => $user]);
    }

    // Membuat akun baru sesuai peran aplikasi.
    public function register(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'role' => 'required|in:bendahara,wali_kelas,siswa',
            'username' => 'nullable|string|max:40',
        ]);

        $generatedUsername = preg_replace('/[^a-z0-9]/i', '', strtolower($data['name'])) ?: 'user';
        $data['username'] = $data['username'] ?? $generatedUsername;
        $data['password'] = Hash::make($data['password']);

        $user = User::create($data);
        AuditLog::record($user->id, 'Membuat akun FinClass');

        return response()->json(['status' => 'success', 'message' => 'Registrasi berhasil!', 'user' => $user], 201);
    }
}
