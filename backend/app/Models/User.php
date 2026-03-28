<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'account',
        'name',
        'phone',
        'role',
        'status',
        'data_scope_type',
        'data_scope',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'data_scope' => 'array',
        ];
    }

    public function vehicle(): HasOne
    {
        return $this->hasOne(Vehicle::class, 'driver_id');
    }

    /**
     * @return array{type:string,region_codes:array<int,string>,site_ids:array<int,int>}
     */
    public function resolveDataScope(): array
    {
        $dataScope = is_array($this->data_scope) ? $this->data_scope : [];

        return [
            'type' => $this->data_scope_type ?: 'all',
            'region_codes' => collect($dataScope['region_codes'] ?? [])
                ->map(fn ($code) => trim((string) $code))
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'site_ids' => collect($dataScope['site_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->unique()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function defaultRolePermissions(?string $role = null): array
    {
        $mapping = [
            'admin' => ['dashboard', 'dispatch', 'users', 'mobile_tasks', 'resources', 'freight_templates', 'settlement', 'notifications', 'audit_log'],
            'dispatcher' => ['dashboard', 'dispatch', 'mobile_tasks', 'resources', 'freight_templates', 'settlement', 'notifications', 'audit_log'],
            'driver' => ['dashboard', 'mobile_tasks', 'notifications'],
            'customer' => ['dashboard', 'customer_orders', 'notifications'],
        ];

        return $role ? ($mapping[$role] ?? []) : array_values(array_unique(array_merge(...array_values($mapping))));
    }

    /**
     * @param  array<int, string>  $extraPermissions
     */
    public function syncRoleAndPermissions(?string $role = null, array $extraPermissions = []): void
    {
        $targetRole = $role ?? $this->role;
        if (! $targetRole) {
            return;
        }

        if ($this->role !== $targetRole) {
            $this->forceFill(['role' => $targetRole])->save();
        }

        $base = self::defaultRolePermissions($targetRole);
        $normalizedExtra = array_values(array_unique($extraPermissions));
        $allPermissions = array_values(array_unique(array_merge($base, $normalizedExtra)));

        foreach ($allPermissions as $permissionName) {
            Permission::findOrCreate($permissionName, 'web');
        }

        $roleModel = Role::findOrCreate($targetRole, 'web');
        $roleModel->syncPermissions($base);
        $this->syncRoles([$roleModel]);
        $this->syncPermissions($normalizedExtra);
    }

    /**
     * @return array<int, string>
     */
    public function resolvePermissions(): array
    {
        return $this->getAllPermissions()
            ->pluck('name')
            ->sort()
            ->values()
            ->all();
    }
}
