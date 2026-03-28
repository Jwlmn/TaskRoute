<?php

namespace App\Http\Controllers\Api\V1\Resource;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ResourcePersonnelController extends Controller
{
    public function list(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'keyword' => ['nullable', 'string'],
            'role' => ['nullable', Rule::in(['admin', 'dispatcher', 'driver', 'customer'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
        ]);

        $data = User::query()
            ->when($payload['keyword'] ?? null, function ($query, $keyword): void {
                $query->where(function ($sub) use ($keyword): void {
                    $sub->where('name', 'like', "%{$keyword}%")
                        ->orWhere('account', 'like', "%{$keyword}%")
                        ->orWhere('phone', 'like', "%{$keyword}%");
                });
            })
            ->when($payload['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->when($payload['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20);

        return response()->json($data);
    }

    public function create(Request $request): JsonResponse
    {
        if ($request->user()?->role !== 'admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $payload = $request->validate([
            'account' => ['required', 'string', 'max:64', 'unique:users,account'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'role' => ['required', Rule::in(['admin', 'dispatcher', 'driver', 'customer'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:64'],
            'password' => ['required', 'string', 'min:6'],
        ]);

        $payload['status'] ??= 'active';
        $payload['password'] = Hash::make($payload['password']);

        return response()->json(User::query()->create($payload), 201);
    }

    public function detail(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:users,id'],
        ]);

        return response()->json(User::query()->findOrFail($payload['id']));
    }

    public function update(Request $request): JsonResponse
    {
        if ($request->user()?->role !== 'admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:users,id'],
            'account' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'role' => ['sometimes', Rule::in(['admin', 'dispatcher', 'driver', 'customer'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'permissions' => ['sometimes', 'nullable', 'array'],
            'permissions.*' => ['string', 'max:64'],
            'password' => ['sometimes', 'string', 'min:6'],
        ]);

        $user = User::query()->findOrFail($payload['id']);
        if (array_key_exists('account', $payload)) {
            validator(
                ['account' => $payload['account']],
                ['account' => [Rule::unique('users', 'account')->ignore($user->id)]]
            )->validate();
        }
        if (array_key_exists('password', $payload)) {
            $payload['password'] = Hash::make($payload['password']);
        }

        unset($payload['id']);
        $user->update($payload);

        return response()->json($user->fresh());
    }
}
