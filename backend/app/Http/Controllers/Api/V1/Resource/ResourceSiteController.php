<?php

namespace App\Http\Controllers\Api\V1\Resource;

use App\Http\Controllers\Controller;
use App\Models\LogisticsSite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ResourceSiteController extends Controller
{
    public function list(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'keyword' => ['nullable', 'string'],
            'site_type' => ['nullable', 'in:pickup,dropoff,both'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $data = LogisticsSite::query()
            ->when($payload['keyword'] ?? null, function ($query, $keyword): void {
                $query->where(function ($sub) use ($keyword): void {
                    $sub->where('name', 'like', "%{$keyword}%")
                        ->orWhere('site_no', 'like', "%{$keyword}%")
                        ->orWhere('address', 'like', "%{$keyword}%");
                });
            })
            ->when($payload['site_type'] ?? null, fn ($query, $siteType) => $query->where('site_type', $siteType))
            ->when($payload['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($data);
    }

    public function create(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'site_type' => ['required', 'in:pickup,dropoff,both'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'address' => ['required', 'string', 'max:255'],
            'lng' => ['nullable', 'numeric'],
            'lat' => ['nullable', 'numeric'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $payload['status'] ??= 'active';
        $payload['site_no'] = 'SITE-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));

        return response()->json(LogisticsSite::query()->create($payload), 201);
    }

    public function detail(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:logistics_sites,id'],
        ]);

        return response()->json(LogisticsSite::query()->findOrFail($payload['id']));
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:logistics_sites,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'site_type' => ['sometimes', 'in:pickup,dropoff,both'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'string', 'max:255'],
            'lng' => ['sometimes', 'nullable', 'numeric'],
            'lat' => ['sometimes', 'nullable', 'numeric'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $site = LogisticsSite::query()->findOrFail($payload['id']);
        unset($payload['id']);
        $site->update($payload);

        return response()->json($site->fresh());
    }
}

