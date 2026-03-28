<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementStatement extends Model
{
    use HasFactory;

    protected $fillable = [
        'statement_no',
        'client_name',
        'period_start',
        'period_end',
        'order_count',
        'total_base_amount',
        'total_loss_deduct_amount',
        'total_freight_amount',
        'status',
        'created_by',
        'confirmed_by',
        'confirmed_at',
        'remark',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'confirmed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}
