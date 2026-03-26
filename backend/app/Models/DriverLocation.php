<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverLocation extends Model
{
    use HasFactory;

    protected $fillable = [
        'driver_id',
        'dispatch_task_id',
        'lng',
        'lat',
        'speed_kmh',
        'located_at',
    ];

    protected function casts(): array
    {
        return [
            'located_at' => 'datetime',
        ];
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(DispatchTask::class, 'dispatch_task_id');
    }
}
