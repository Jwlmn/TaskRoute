<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

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
        'permissions',
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
            'permissions' => 'array',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function resolvePermissions(): array
    {
        $rolePermissions = match ($this->role) {
            'admin' => ['dashboard', 'dispatch', 'users', 'mobile_tasks', 'resources', 'freight_templates', 'settlement', 'notifications', 'audit_log'],
            'dispatcher' => ['dashboard', 'dispatch', 'mobile_tasks', 'resources', 'freight_templates', 'settlement', 'notifications', 'audit_log'],
            'driver' => ['dashboard', 'mobile_tasks', 'notifications'],
            'customer' => ['dashboard', 'customer_orders', 'notifications'],
            default => [],
        };
        $custom = is_array($this->permissions) ? $this->permissions : [];

        return array_values(array_unique(array_merge($rolePermissions, $custom)));
    }
}
