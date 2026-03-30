<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreightRateTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'client_name',
        'cargo_category_id',
        'pickup_site_id',
        'pickup_address',
        'dropoff_site_id',
        'dropoff_address',
        'freight_calc_scheme',
        'freight_unit_price',
        'freight_trip_count',
        'loss_allowance_kg',
        'loss_deduct_unit_price',
        'priority',
        'is_active',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function cargoCategory(): BelongsTo
    {
        return $this->belongsTo(CargoCategory::class);
    }

    public function pickupSite(): BelongsTo
    {
        return $this->belongsTo(LogisticsSite::class, 'pickup_site_id');
    }

    public function dropoffSite(): BelongsTo
    {
        return $this->belongsTo(LogisticsSite::class, 'dropoff_site_id');
    }
}
