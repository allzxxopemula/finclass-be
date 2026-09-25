<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatRoom;
use App\Models\ChatMessage;
use App\Models\ChatRoomRead;
use App\Models\Kelas;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class ChatController extends Controller
{
    protected function resolveRoomName(int $kelasId): string
    {
        $kelas = Kelas::find($kelasId);

        return $kelas?->nama_kelas ?? ($kelas?->name ?? 'Room Kelas ' . $kelasId);
    }

    protected function hasDeletedAtColumn(): bool
    {
        return Schema::hasColumn('chat_messages', 'deleted_at');
    }

    protected function unreadCountForUser(ChatRoom $room, int $userId): int
    {
        $lastRead = ChatRoomRead::where('room_id', $room->id)
            ->where('user_id', $userId)
            ->value('last_read_at');

        $query = $room->messages()->where('user_id', '!=', $userId);

        if ($this->hasDeletedAtColumn()) {
            $query->whereNull('deleted_at');
        }

        if ($lastRead) {
            $query->where('created_at', '>', $lastRead);
        }

        return (int) $query->count();
    }

    public function index(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($request->user_id);

        if (!$user->kelas_id) {
            return response()->json([
                'status' => 'empty',
                'message' => 'Kamu belum masuk ke room kelas.',
            ], 200);
        }

        $roomName = $this->resolveRoomName($user->kelas_id);
        $room = ChatRoom::firstOrCreate([
            'kelas_id' => $user->kelas_id,
        ], [
            'name' => $roomName,
        ]);

        if ($room->name !== $roomName) {
            $room->name = $roomName;
            $room->save();
        }

        $room->cleanupExpiredMessages();

        $unreadCount = $this->unreadCountForUser($room, $user->id);

        ChatRoomRead::updateOrCreate(
            ['room_id' => $room->id, 'user_id' => $user->id],
            ['last_read_at' => now()]
        );

        $messagesQuery = $room->messages()
            ->with('user:id,name,email,username,profile_image_url')
            ->orderBy('created_at', 'asc');

        $messages = $messagesQuery->get()
            ->map(function ($message) {
                $deletedAt = $this->hasDeletedAtColumn() ? $message->deleted_at : null;
                $isDeleted = (bool) $deletedAt;

                return [
                    'id' => $message->id,
                    'message' => $isDeleted || str_contains((string) $message->message, 'Pesan ini telah dihapus') ? 'Pesan ini telah dihapus' : $message->message,
                    'deleted_at' => $deletedAt ? $deletedAt->toIso8601String() : null,
                    'is_deleted' => $isDeleted || str_contains((string) $message->message, 'Pesan ini telah dihapus'),
                    'created_at' => $message->created_at->toIso8601String(),
                    'user' => [
                        'id' => $message->user?->id,
                        'name' => $message->user?->name,
                        'email' => $message->user?->email,
                        'username' => $message->user?->username,
                        'profile_image_url' => $message->user?->profile_image_url,
                    ],
                ];
            });

        return response()->json([
            'status' => 'success',
            'room' => [
                'id' => $room->id,
                'kelas_id' => $room->kelas_id,
                'name' => $room->name,
            ],
            'messages' => $messages,
            'unread_count' => $unreadCount,
        ]);
    }

    public function markAsRead(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'room_id' => 'nullable|exists:chat_rooms,id',
        ]);

        $user = User::findOrFail($request->user_id);

        $room = null;
        if ($request->room_id) {
            $room = ChatRoom::findOrFail($request->room_id);
        } elseif ($user->kelas_id) {
            $room = ChatRoom::firstOrCreate([
                'kelas_id' => $user->kelas_id,
            ], [
                'name' => $this->resolveRoomName($user->kelas_id),
            ]);
        }

        if (!$room) {
            return response()->json([
                'status' => 'success',
                'unread_count' => 0,
            ]);
        }

        $read = ChatRoomRead::updateOrCreate(
            ['room_id' => $room->id, 'user_id' => $user->id],
            ['last_read_at' => now()]
        );

        return response()->json([
            'status' => 'success',
            'last_read_at' => $read->last_read_at?->toIso8601String(),
            'unread_count' => 0,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'message' => 'required|string|min:1|max:500',
        ]);

        $user = User::findOrFail($request->user_id);

        if (!$user->kelas_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kamu belum masuk kelas, jadi tidak bisa kirim chat.',
            ], 403);
        }

        $roomName = $this->resolveRoomName($user->kelas_id);
        $room = ChatRoom::firstOrCreate([
            'kelas_id' => $user->kelas_id,
        ], [
            'name' => $roomName,
        ]);

        if ($room->name !== $roomName) {
            $room->name = $roomName;
            $room->save();
        }

        $recentMessage = ChatMessage::where('user_id', $user->id)
            ->where('created_at', '>=', now()->subSeconds(5))
            ->exists();

        if ($recentMessage) {
            return response()->json([
                'status' => 'error',
                'message' => 'Tunggu 5 detik sebelum kirim pesan lagi.',
            ], 429);
        }

        $room->cleanupExpiredMessages();

        $message = ChatMessage::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'message' => trim($request->message),
        ]);

        $room->refresh();

        return response()->json([
            'status' => 'success',
            'message' => 'Pesan terkirim.',
            'chat' => [
                'id' => $message->id,
                'message' => $message->message,
                'created_at' => $message->created_at->toIso8601String(),
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'username' => $user->username,
                    'profile_image_url' => $user->profile_image_url,
                ],
            ],
        ]);
    }

    public function destroy(Request $request, ChatMessage $message)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
        ]);

        if ((int) $message->user_id !== (int) $request->user_id) {
            return response()->json([
                'status' => 'error',
                'message' => 'Kamu hanya bisa menghapus pesan milikmu sendiri.',
            ], 403);
        }

        $message->message = 'Pesan ini telah dihapus';

        if ($this->hasDeletedAtColumn()) {
            $message->deleted_at = now();
        }

        $message->save();

        return response()->json([
            'status' => 'success',
            'message' => 'Pesan dihapus.',
            'chat' => [
                'id' => $message->id,
                'message' => $message->message,
                'deleted_at' => $this->hasDeletedAtColumn() && $message->deleted_at ? $message->deleted_at->toIso8601String() : null,
                'is_deleted' => true,
            ],
        ]);
    }
}
