<?php

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows a user to delete their own chat message and keeps a deleted placeholder', function () {
    $user = User::factory()->create([
        'name' => 'Citra',
        'email' => 'citra@example.com',
        'username' => 'citra',
        'role' => 'siswa',
        'kelas_id' => 7,
    ]);

    $room = ChatRoom::create([
        'kelas_id' => 7,
        'name' => 'Room Kelas 7',
    ]);

    $message = ChatMessage::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'message' => 'Halo semua',
    ]);

    $response = $this->deleteJson('/api/chat-room/message/' . $message->id, [
        'user_id' => $user->id,
    ]);

    $response->assertOk();
    $response->assertJsonPath('status', 'success');

    $message->refresh();
    expect($message->deleted_at)->not->toBeNull();
    expect($message->message)->toBe('Pesan ini telah dihapus');
});
