<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('stores a custom username persistently on the user record', function () {
    $user = User::create([
        'name' => 'Aldo Rendy',
        'email' => 'aldo.custom@example.com',
        'password' => Hash::make('password123'),
        'role' => 'siswa',
        'username' => 'aldodev',
    ]);

    expect($user->fresh()->username)->toBe('aldodev');
});
