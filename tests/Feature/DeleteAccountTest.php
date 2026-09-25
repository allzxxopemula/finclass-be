<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes a user account after matching the confirmation phrase', function () {
    $user = User::factory()->create([
        'name' => 'Aldi Pratama',
        'email' => 'aldi@example.com',
        'username' => 'aldipr',
        'role' => 'siswa',
    ]);

    $expected = 'Saya ingin menghapus akun aldipr secara permanen';

    $response = $this->deleteJson('/api/delete-account', [
        'user_id' => $user->id,
        'confirmation_text' => $expected,
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'success');
    $this->assertDatabaseMissing('users', ['id' => $user->id]);
});
