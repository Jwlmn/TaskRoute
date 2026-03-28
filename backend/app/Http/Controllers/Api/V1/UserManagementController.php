<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    private function serializeUser(User $user): array
    {
        return array_merge($user->toArray(), [
            'permissions' => $user->resolvePermissions(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $role = $request->query('role');

        $users = User::query()
            ->when($role, fn ($query) => $query->where('role', $role))
            ->orderBy('id')
            ->paginate(20);
        $users->getCollection()->transform(fn (User $user) => $this->serializeUser($user));

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'account' => ['required', 'string', 'max:64', 'unique:users,account'],
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'role' => ['required', Rule::in(['admin', 'dispatcher', 'driver', 'customer'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:64'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $payload['status'] ??= 'active';
        $extraPermissions = $payload['permissions'] ?? [];
        unset($payload['permissions']);
        $payload['password'] = Hash::make($payload['password']);

        $user = User::query()->create($payload);
        $user->syncRoleAndPermissions($user->role, $extraPermissions);

        return response()->json($this->serializeUser($user->fresh()), 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($this->serializeUser($user));
    }

    public function showByPayload(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $user = User::query()->findOrFail($payload['id']);

        return response()->json($this->serializeUser($user));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $payload = $request->validate([
            'account' => ['sometimes', 'string', 'max:64', Rule::unique('users', 'account')->ignore($user->id)],
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'role' => ['sometimes', Rule::in(['admin', 'dispatcher', 'driver', 'customer'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'permissions' => ['sometimes', 'nullable', 'array'],
            'permissions.*' => ['string', 'max:64'],
            'password' => ['sometimes', 'string', 'min:8'],
        ]);

        if (array_key_exists('password', $payload)) {
            $payload['password'] = Hash::make($payload['password']);
        }
        $extraPermissions = $payload['permissions'] ?? null;
        unset($payload['permissions']);

        $user->update($payload);
        if (array_key_exists('role', $payload) || $extraPermissions !== null) {
            $user->syncRoleAndPermissions($payload['role'] ?? $user->role, $extraPermissions ?? []);
        }

        return response()->json($this->serializeUser($user->fresh()));
    }

    public function updateByPayload(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'id' => ['required', 'integer', 'exists:users,id'],
            'account' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'role' => ['sometimes', Rule::in(['admin', 'dispatcher', 'driver', 'customer'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'permissions' => ['sometimes', 'nullable', 'array'],
            'permissions.*' => ['string', 'max:64'],
            'password' => ['sometimes', 'string', 'min:8'],
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
        $extraPermissions = $payload['permissions'] ?? null;
        $targetRole = $payload['role'] ?? $user->role;

        unset($payload['id']);
        unset($payload['permissions']);
        $user->update($payload);
        if (array_key_exists('role', $payload) || $extraPermissions !== null) {
            $user->syncRoleAndPermissions($targetRole, $extraPermissions ?? []);
        }

        return response()->json($this->serializeUser($user->fresh()));
    }
}
