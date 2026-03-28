<?php

namespace App\Http\Controllers\Api\V1\Resource;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\Auth\DataScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResourceVehicleController extends Controller
{
    public function __construct(private readonly DataScopeService $dataScopeService)
    {
    }

    public function list(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'keyword' => ['nullable', 'string'],
            'status' => ['nullable', 'in:idle,busy,maintenance'],
            'site_id' => ['nullable', 'integer', 'exists:logistics_sites,id'],
        ]);

        $data = $this->dataScopeService->applyVehicleScope(Vehicle::query(), $request->user())
            ->with(['driver:id,account,name,status', 'site:id,name,site_no,region_code'])
            ->when($payload['keyword'] ?? null, function ($query, $keyword): void {
                $query->where(function ($sub) use ($keyword): void {
                    $sub->where('name', 'like', "%{$keyword}%")
                        ->orWhere('plate_number', 'like', "%{$keyword}%");
                });
            })
            ->when($payload['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($payload['site_id'] ?? null, fn ($query, $siteId) => $query->where('site_id', $siteId))
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($data);
    }

    public function create(Request $request): JsonResponse
    {
        $payload = $request->validate(
            [
                'plate_number' => ['required', 'string', 'max:64', 'unique:vehicles,plate_number'],
                'name' => ['required', 'string', 'max:255'],
                'vehicle_type' => ['required', 'string', 'max:64'],
                'site_id' => ['required', 'integer', 'exists:logistics_sites,id'],
                'driver_id' => [
                    'required',
                    'integer',
                    Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'driver')->where('status', 'active')),
                    'unique:vehicles,driver_id',
                ],
                'max_weight_kg' => ['nullable', 'numeric', 'min:0'],
                'max_volume_m3' => ['nullable', 'numeric', 'min:0'],
                'status' => ['nullable', 'in:idle,busy,maintenance'],
                'meta' => ['nullable', 'array'],
            ],
            [
                'driver_id.unique' => '该司机已绑定其他车辆，请先解绑后再分配',
            ]
        );

        if (! $this->dataScopeService->canAccessSite($request->user(), (int) $payload['site_id'])) {
            return response()->json(['message' => '当前账号不可分配该站点车辆'], 403);
        }
        $payload['status'] ??= 'idle';
        $vehicle = Vehicle::query()->create($payload);

        return response()->json($vehicle->fresh(['driver:id,account,name,status', 'site:id,name,site_no,region_code']), 201);
    }

    public function detail(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:vehicles,id'],
        ]);

        $vehicle = $this->dataScopeService->applyVehicleScope(Vehicle::query(), $request->user())
            ->with(['driver:id,account,name,status', 'site:id,name,site_no,region_code'])
            ->findOrFail($payload['id']);

        return response()->json($vehicle);
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:vehicles,id'],
            'plate_number' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:255'],
            'vehicle_type' => ['sometimes', 'string', 'max:64'],
            'site_id' => ['sometimes', 'integer', 'exists:logistics_sites,id'],
            'driver_id' => [
                'sometimes',
                'integer',
                Rule::exists('users', 'id')->where(fn ($query) => $query->where('role', 'driver')->where('status', 'active')),
            ],
            'max_weight_kg' => ['sometimes', 'numeric', 'min:0'],
            'max_volume_m3' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:idle,busy,maintenance'],
            'meta' => ['sometimes', 'array'],
        ]);

        $vehicle = $this->dataScopeService->applyVehicleScope(Vehicle::query(), $request->user())
            ->findOrFail($payload['id']);
        if (array_key_exists('plate_number', $payload)) {
            validator(
                ['plate_number' => $payload['plate_number']],
                ['plate_number' => ['unique:vehicles,plate_number,'.$vehicle->id]]
            )->validate();
        }
        if (array_key_exists('driver_id', $payload)) {
            validator(
                ['driver_id' => $payload['driver_id']],
                ['driver_id' => ['unique:vehicles,driver_id,'.$vehicle->id]],
                ['driver_id.unique' => '该司机已绑定其他车辆，请先解绑后再分配']
            )->validate();
        }
        if (array_key_exists('site_id', $payload) && ! $this->dataScopeService->canAccessSite($request->user(), (int) $payload['site_id'])) {
            return response()->json(['message' => '当前账号不可分配该站点车辆'], 403);
        }

        unset($payload['id']);
        $vehicle->update($payload);

        return response()->json($vehicle->fresh(['driver:id,account,name,status', 'site:id,name,site_no,region_code']));
    }
}
