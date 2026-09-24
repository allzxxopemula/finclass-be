<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatRoom;
use App\Models\ChatMessage;
use App\Models\User;
use Illuminate\Http\Request;

class ChatController extends Controller
{
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

        $room = ChatRoom::firstOrCreate([
            'kelas_id' => $user->kelas_id,
        ], [
            'name' => 'Room Kelas ' . $user->kelas_id,
        ]);

        $room->cleanupExpiredMessages();

        $messages = $room->messages()
            ->with('user:id,name,email,username,profile_image_url')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($message) {
                return [
                    'id' => $message->id,
                    'message' => $message->message,
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

        $room = ChatRoom::firstOrCreate([
            'kelas_id' => $user->kelas_id,
        ], [
            'name' => 'Room Kelas ' . $user->kelas_id,
        ]);

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
}
