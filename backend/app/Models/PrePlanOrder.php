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
        'submitter_id',
        'client_name',
        'pickup_address',
        'pickup_contact_name',
        'pickup_contact_phone',
        'dropoff_address',
        'dropoff_contact_name',
        'dropoff_contact_phone',
        'cargo_weight_kg',
        'cargo_volume_m3',
        'freight_calc_scheme',
        'freight_unit_price',
        'freight_trip_count',
        'actual_delivered_weight_kg',
        'loss_allowance_kg',
        'loss_deduct_unit_price',
        'freight_base_amount',
        'freight_loss_deduct_amount',
        'freight_amount',
        'freight_calculated_at',
        'expected_pickup_at',
        'expected_delivery_at',
        'audit_status',
        'audited_by',
        'audited_at',
        'audit_remark',
        'is_locked',
        'voided_by',
        'voided_at',
        'void_remark',
        'status',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'expected_pickup_at' => 'datetime',
            'expected_delivery_at' => 'datetime',
            'freight_calculated_at' => 'datetime',
            'audited_at' => 'datetime',
            'voided_at' => 'datetime',
            'is_locked' => 'boolean',
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
