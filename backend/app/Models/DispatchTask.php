<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class DispatchTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_no',
        'vehicle_id',
        'driver_id',
        'dispatcher_id',
        'dispatch_mode',
        'status',
        'estimated_distance_km',
        'estimated_fuel_l',
        'route_meta',
        'planned_start_at',
        'planned_end_at',
    ];

    protected function casts(): array
    {
        return [
            'route_meta' => 'array',
            'planned_start_at' => 'datetime',
            'planned_end_at' => 'datetime',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function dispatcher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'dispatcher_id');
    }

    public function orders(): BelongsToMany
    {
        return $this->belongsToMany(PrePlanOrder::class, 'dispatch_task_orders')
            ->withPivot('sequence')
            ->withTimestamps();
    }

    public function waypoints(): HasMany
    {
        return $this->hasMany(TaskWaypoint::class)->orderBy('sequence');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ElectronicDocument::class)->latest('uploaded_at');
    }
}
