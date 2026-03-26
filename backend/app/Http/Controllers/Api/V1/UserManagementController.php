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
    public function index(Request $request): JsonResponse
    {
        $role = $request->query('role');

        $users = User::query()
            ->when($role, fn ($query) => $query->where('role', $role))
            ->orderBy('id')
            ->paginate(20);

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'role' => ['required', Rule::in(['admin', 'dispatcher', 'driver'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $payload['status'] ??= 'active';
        $payload['password'] = Hash::make($payload['password']);

        $user = User::query()->create($payload);

        return response()->json($user, 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $payload = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'role' => ['sometimes', Rule::in(['admin', 'dispatcher', 'driver'])],
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'password' => ['sometimes', 'string', 'min:8'],
        ]);

        if (array_key_exists('password', $payload)) {
            $payload['password'] = Hash::make($payload['password']);
        }

        $user->update($payload);

        return response()->json($user->fresh());
    }
}

