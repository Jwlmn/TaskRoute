<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LogisticsSite extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_no',
        'name',
        'site_type',
        'organization_code',
        'region_code',
        'contact_person',
        'contact_phone',
        'address',
        'lng',
        'lat',
        'status',
    ];

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'site_id');
    }

    public function pickupOrders(): HasMany
    {
        return $this->hasMany(PrePlanOrder::class, 'pickup_site_id');
    }

    public function dropoffOrders(): HasMany
    {
        return $this->hasMany(PrePlanOrder::class, 'dropoff_site_id');
    }
}
