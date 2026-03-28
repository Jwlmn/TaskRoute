<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (User::defaultRolePermissions() as $permissionName) {
            Permission::query()->firstOrCreate([
                'name' => $permissionName,
                'guard_name' => 'web',
            ]);
        }

        foreach (['admin', 'dispatcher', 'driver', 'customer'] as $role) {
            $roleModel = Role::query()->firstOrCreate([
                'name' => $role,
                'guard_name' => 'web',
            ]);
            $roleModel->syncPermissions(User::defaultRolePermissions($role));
        }
    }
}
