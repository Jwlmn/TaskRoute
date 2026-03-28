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
            'read_status' => ['nullable', 'in:all,read,unread'],
            'message_type' => ['nullable', 'string', 'max:64'],
            'keyword' => ['nullable', 'string', 'max:100'],
            'pinned_only' => ['nullable', 'boolean'],
        ]);

        $keyword = trim((string) ($payload['keyword'] ?? ''));
        $readStatus = (string) ($payload['read_status'] ?? 'all');
        if (($payload['unread_only'] ?? false) === true) {
            $readStatus = 'unread';
        }

        $messages = SystemMessage::query()
            ->where('user_id', (int) $request->user()->id)
            ->when($readStatus === 'unread', fn ($query) => $query->whereNull('read_at'))
            ->when($readStatus === 'read', fn ($query) => $query->whereNotNull('read_at'))
            ->when($payload['message_type'] ?? null, fn ($query, $type) => $query->where('message_type', $type))
            ->when(($payload['pinned_only'] ?? false) === true, fn ($query) => $query->where('is_pinned', true))
            ->when($keyword !== '', function ($query) use ($keyword): void {
                $query->where(function ($sub) use ($keyword): void {
                    $sub->where('title', 'like', "%{$keyword}%")
                        ->orWhere('content', 'like', "%{$keyword}%");
                });
            })
            ->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
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

    public function markReadBatch(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'exists:system_messages,id'],
        ]);

        $count = SystemMessage::query()
            ->where('user_id', (int) $request->user()->id)
            ->whereIn('id', $payload['ids'])
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['updated_count' => $count]);
    }

    public function togglePin(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:system_messages,id'],
            'is_pinned' => ['required', 'boolean'],
        ]);

        $message = SystemMessage::query()->findOrFail((int) $payload['id']);
        if ((int) $message->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $message->is_pinned = (bool) $payload['is_pinned'];
        $message->save();

        return response()->json($message);
    }
}
