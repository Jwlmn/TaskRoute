<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Model;

class PrePlanOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_no',
        'cargo_category_id',
        'client_name',
        'pickup_address',
        'dropoff_address',
        'cargo_weight_kg',
        'cargo_volume_m3',
        'expected_pickup_at',
        'expected_delivery_at',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'expected_pickup_at' => 'datetime',
            'expected_delivery_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function cargoCategory(): BelongsTo
    {
        return $this->belongsTo(CargoCategory::class);
    }

    public function dispatchTasks(): BelongsToMany
    {
        return $this->belongsToMany(DispatchTask::class, 'dispatch_task_orders')
            ->withPivot('sequence')
            ->withTimestamps();
    }
}
