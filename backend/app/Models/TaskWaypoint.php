<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TaskWaypoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'dispatch_task_id',
        'node_type',
        'sequence',
        'address',
        'lng',
        'lat',
        'status',
        'arrived_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'arrived_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(DispatchTask::class, 'dispatch_task_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ElectronicDocument::class, 'task_waypoint_id')->latest('uploaded_at');
    }
}
