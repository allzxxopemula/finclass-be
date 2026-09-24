<?php

use App\Models\ChatMessage;
use App\Models\ChatRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes expired chat messages older than seven days', function () {
    $user = User::factory()->create([
        'name' => 'Aldo',
        'email' => 'aldo@example.com',
        'username' => 'aldo',
        'role' => 'siswa',
        'kelas_id' => 1,
    ]);

    $room = ChatRoom::create([
        'kelas_id' => 1,
        'name' => 'Room Kelas 1',
    ]);

    $oldMessage = ChatMessage::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'message' => 'pesan lama',
    ]);
    $oldMessage->created_at = now()->subDays(8);
    $oldMessage->updated_at = now()->subDays(8);
    $oldMessage->save();

    ChatMessage::create([
        'room_id' => $room->id,
        'user_id' => $user->id,
        'message' => 'pesan baru',
    ]);

    $room->cleanupExpiredMessages();

    expect(ChatMessage::count())->toBe(1);
    expect(ChatMessage::first()->message)->toBe('pesan baru');
});
