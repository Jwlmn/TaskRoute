<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DispatchTask;
use App\Models\PrePlanOrder;
use App\Models\SystemMessage;
use App\Models\User;
use App\Services\Auth\DataScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class SystemMessageController extends Controller
{
    public function __construct(private readonly DataScopeService $dataScopeService)
    {
    }

    public function list(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'unread_only' => ['nullable', 'boolean'],
            'read_status' => ['nullable', 'in:all,read,unread'],
            'message_type' => ['nullable', 'string', 'max:64'],
            'keyword' => ['nullable', 'string', 'max:100'],
            'pinned_only' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
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
            ->get();

        $messages = $this->filterMessagesByDataScope($messages, $request->user());
        $page = (int) ($payload['page'] ?? 1);
        $perPage = 20;
        $paginated = new LengthAwarePaginator(
            $messages->forPage($page, $perPage)->values(),
            $messages->count(),
            $perPage,
            $page
        );

        return response()->json($paginated);
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
        if (! $this->canAccessMessage($message, $request->user())) {
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
            ->get()
            ->filter(fn (SystemMessage $message) => $this->canAccessMessage($message, $request->user()))
            ->tap(function (Collection $messages): void {
                if ($messages->isNotEmpty()) {
                    SystemMessage::query()
                        ->whereIn('id', $messages->pluck('id')->all())
                        ->update(['read_at' => now()]);
                }
            })
            ->count();

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
        if (! $this->canAccessMessage($message, $request->user())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $message->is_pinned = (bool) $payload['is_pinned'];
        $message->save();

        return response()->json($message);
    }

    private function filterMessagesByDataScope(Collection $messages, ?User $user): Collection
    {
        if (! $user || $user->hasRole('admin')) {
            return $messages->values();
        }

        $orderIds = $messages
            ->flatMap(fn (SystemMessage $message) => $this->extractMetaIds($message->meta, 'order_id', 'order_ids'))
            ->unique()
            ->values();
        $taskIds = $messages
            ->flatMap(fn (SystemMessage $message) => $this->extractMetaIds($message->meta, 'task_id', 'task_ids'))
            ->unique()
            ->values();

        $accessibleOrderIds = $this->dataScopeService->applyPrePlanOrderScope(PrePlanOrder::query(), $user)
            ->when($orderIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $orderIds->all()))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
        $accessibleTaskIds = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), $user)
            ->when($taskIds->isNotEmpty(), fn ($query) => $query->whereIn('id', $taskIds->all()))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $orderSet = collect($accessibleOrderIds)->flip();
        $taskSet = collect($accessibleTaskIds)->flip();

        return $messages->filter(function (SystemMessage $message) use ($orderSet, $taskSet, $user): bool {
            return $this->canAccessMessage($message, $user, $orderSet, $taskSet);
        })->values();
    }

    private function canAccessMessage(
        SystemMessage $message,
        ?User $user,
        ?Collection $orderSet = null,
        ?Collection $taskSet = null
    ): bool {
        if (! $user || $user->hasRole('admin')) {
            return true;
        }

        $meta = is_array($message->meta) ? $message->meta : [];

        if ($orderSet === null) {
            $orderIds = $this->extractMetaIds($meta, 'order_id', 'order_ids');
            $accessibleOrderIds = $this->dataScopeService->applyPrePlanOrderScope(PrePlanOrder::query(), $user)
                ->when($orderIds !== [], fn ($query) => $query->whereIn('id', $orderIds))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
            $orderSet = collect($accessibleOrderIds)->flip();
        }

        if ($taskSet === null) {
            $taskIds = $this->extractMetaIds($meta, 'task_id', 'task_ids');
            $accessibleTaskIds = $this->dataScopeService->applyDispatchTaskScope(DispatchTask::query(), $user)
                ->when($taskIds !== [], fn ($query) => $query->whereIn('id', $taskIds))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
            $taskSet = collect($accessibleTaskIds)->flip();
        }

        $orderIds = $this->extractMetaIds($meta, 'order_id', 'order_ids');
        if ($orderIds !== [] && collect($orderIds)->contains(fn ($id) => ! $orderSet->has((int) $id))) {
            return false;
        }

        $taskIds = $this->extractMetaIds($meta, 'task_id', 'task_ids');
        if ($taskIds !== [] && collect($taskIds)->contains(fn ($id) => ! $taskSet->has((int) $id))) {
            return false;
        }

        $siteIds = $this->extractMetaIds($meta, 'site_id', 'site_ids');
        if ($siteIds !== [] && ! $this->dataScopeService->canAccessSites($user, $siteIds)) {
            return false;
        }

        return true;
    }

    /**
     * @return array<int, int>
     */
    private function extractMetaIds(array $meta, string $singleKey, string $listKey): array
    {
        $ids = [];
        if (array_key_exists($singleKey, $meta)) {
            $ids[] = (int) $meta[$singleKey];
        }
        $list = $meta[$listKey] ?? [];
        if (is_array($list)) {
            foreach ($list as $id) {
                $ids[] = (int) $id;
            }
        }

        return collect($ids)->filter(fn ($id) => $id > 0)->unique()->values()->all();
    }
}
