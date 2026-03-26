<?php

namespace App\Http\Controllers\Api\V1\Resource;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourceVehicleController extends Controller
{
    public function list(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'keyword' => ['nullable', 'string'],
            'status' => ['nullable', 'in:idle,busy,maintenance'],
        ]);

        $data = Vehicle::query()
            ->when($payload['keyword'] ?? null, function ($query, $keyword): void {
                $query->where(function ($sub) use ($keyword): void {
                    $sub->where('name', 'like', "%{$keyword}%")
                        ->orWhere('plate_number', 'like', "%{$keyword}%");
                });
            })
            ->when($payload['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($data);
    }

    public function create(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'plate_number' => ['required', 'string', 'max:64', 'unique:vehicles,plate_number'],
            'name' => ['required', 'string', 'max:255'],
            'vehicle_type' => ['required', 'string', 'max:64'],
            'max_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'max_volume_m3' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:idle,busy,maintenance'],
            'meta' => ['nullable', 'array'],
        ]);

        $payload['status'] ??= 'idle';
        $vehicle = Vehicle::query()->create($payload);

        return response()->json($vehicle, 201);
    }

    public function detail(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:vehicles,id'],
        ]);

        return response()->json(Vehicle::query()->findOrFail($payload['id']));
    }

    public function update(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:vehicles,id'],
            'plate_number' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:255'],
            'vehicle_type' => ['sometimes', 'string', 'max:64'],
            'max_weight_kg' => ['sometimes', 'numeric', 'min:0'],
            'max_volume_m3' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', 'in:idle,busy,maintenance'],
            'meta' => ['sometimes', 'array'],
        ]);

        $vehicle = Vehicle::query()->findOrFail($payload['id']);
        if (array_key_exists('plate_number', $payload)) {
            validator(
                ['plate_number' => $payload['plate_number']],
                ['plate_number' => ['unique:vehicles,plate_number,'.$vehicle->id]]
            )->validate();
        }

        unset($payload['id']);
        $vehicle->update($payload);

        return response()->json($vehicle->fresh());
    }
}

