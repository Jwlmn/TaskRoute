<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
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
}

