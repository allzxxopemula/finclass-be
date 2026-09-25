<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ChatRoom extends Model
{
    protected $table = 'chat_rooms';

    protected $fillable = [
        'kelas_id',
        'name',
    ];

    public function messages()
    {
        return $this->hasMany(ChatMessage::class, 'room_id');
    }

    public function reads()
    {
        return $this->hasMany(ChatRoomRead::class, 'room_id');
    }

    public function cleanupExpiredMessages(): void
    {
        $this->messages()
            ->where('created_at', '<', now()->subDays(7))
            ->delete();
    }
}
