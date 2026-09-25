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

it('keeps unread count accurate without resetting last read while loading the room', function () {
    $owner = User::factory()->create([
        'name' => 'Ayu',
        'email' => 'ayu@example.com',
        'username' => 'ayu',
        'role' => 'bendahara',
        'kelas_id' => 8,
    ]);

    $member = User::factory()->create([
        'name' => 'Budi',
        'email' => 'budi@example.com',
        'username' => 'budi',
        'role' => 'siswa',
        'kelas_id' => 8,
    ]);

    $room = ChatRoom::create([
        'kelas_id' => 8,
        'name' => 'Room Kelas 8',
    ]);

    \App\Models\ChatRoomRead::updateOrCreate(
        ['room_id' => $room->id, 'user_id' => $member->id],
        ['last_read_at' => now()->subMinutes(5)]
    );

    ChatMessage::create([
        'room_id' => $room->id,
        'user_id' => $owner->id,
        'message' => 'Halo baru',
    ]);

    $lastReadBefore = now()->subMinutes(5);
    \App\Models\ChatRoomRead::updateOrCreate(
        ['room_id' => $room->id, 'user_id' => $member->id],
        ['last_read_at' => $lastReadBefore]
    );

    $response = $this->getJson('/api/chat-room?user_id=' . $member->id);

    $response->assertOk();
    $response->assertJsonPath('status', 'success');
    $response->assertJsonPath('unread_count', 1);

    $read = \App\Models\ChatRoomRead::where('room_id', $room->id)
        ->where('user_id', $member->id)
        ->first();

    expect($read?->last_read_at?->timestamp ?? 0)->toBe((int) $lastReadBefore->timestamp);
});
