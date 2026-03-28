<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SystemMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemMessageController extends Controller
{
    public function list(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'unread_only' => ['nullable', 'boolean'],
        ]);

        $messages = SystemMessage::query()
            ->where('user_id', (int) $request->user()->id)
            ->when(($payload['unread_only'] ?? false) === true, fn ($query) => $query->whereNull('read_at'))
            ->latest()
            ->paginate(20);

        return response()->json($messages);
    }

    public function markRead(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:system_messages,id'],
        ]);

        $message = SystemMessage::query()->findOrFail($payload['id']);
        if ((int) $message->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! $message->read_at) {
            $message->read_at = now();
            $message->save();
        }

        return response()->json($message);
    }
}
