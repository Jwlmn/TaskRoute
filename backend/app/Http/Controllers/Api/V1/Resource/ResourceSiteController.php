<?php

namespace App\Http\Controllers\Api\V1\Resource;

use App\Http\Controllers\Controller;
use App\Models\LogisticsSite;
use App\Services\Auth\DataScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ResourceSiteController extends Controller
{
    public function __construct(private readonly DataScopeService $dataScopeService)
    {
    }

    public function list(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'keyword' => ['nullable', 'string'],
            'site_type' => ['nullable', 'in:pickup,dropoff,both'],
            'status' => ['nullable', 'in:active,inactive'],
            'organization_code' => ['nullable', 'string', 'max:64'],
            'region_code' => ['nullable', 'string', 'max:64'],
        ]);

        $data = $this->dataScopeService->applySiteScope(LogisticsSite::query(), $request->user())
            ->when($payload['keyword'] ?? null, function ($query, $keyword): void {
                $query->where(function ($sub) use ($keyword): void {
                    $sub->where('name', 'like', "%{$keyword}%")
                        ->orWhere('site_no', 'like', "%{$keyword}%")
                        ->orWhere('address', 'like', "%{$keyword}%");
                });
            })
            ->when($payload['site_type'] ?? null, fn ($query, $siteType) => $query->where('site_type', $siteType))
            ->when($payload['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($payload['organization_code'] ?? null, fn ($query, $organizationCode) => $query->where('organization_code', $organizationCode))
            ->when($payload['region_code'] ?? null, fn ($query, $regionCode) => $query->where('region_code', $regionCode))
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($data);
    }

    public function create(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'site_type' => ['required', 'in:pickup,dropoff,both'],
            'organization_code' => ['nullable', 'string', 'max:64'],
            'region_code' => ['required', 'string', 'max:64'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:32'],
            'address' => ['required', 'string', 'max:255'],
            'lng' => ['nullable', 'numeric'],
            'lat' => ['nullable', 'numeric'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);

        $payload['status'] ??= 'active';
        $payload['organization_code'] ??= 'SH';
        $payload['site_no'] = 'SITE-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));

        return response()->json(LogisticsSite::query()->create($payload), 201);
    }

    public function detail(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:logistics_sites,id'],
        ]);

        $site = $this->dataScopeService->applySiteScope(LogisticsSite::query(), $request->user())
            ->findOrFail($payload['id']);

        return response()->json($site);
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:logistics_sites,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'site_type' => ['sometimes', 'in:pickup,dropoff,both'],
            'organization_code' => ['sometimes', 'string', 'max:64'],
            'region_code' => ['sometimes', 'string', 'max:64'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'string', 'max:255'],
            'lng' => ['sometimes', 'nullable', 'numeric'],
            'lat' => ['sometimes', 'nullable', 'numeric'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        $site = $this->dataScopeService->applySiteScope(LogisticsSite::query(), $request->user())
            ->findOrFail($payload['id']);
        unset($payload['id']);
        $site->update($payload);

        return response()->json($site->fresh());
    }
}
