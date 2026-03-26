<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ElectronicDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'dispatch_task_id',
        'uploaded_by',
        'document_type',
        'file_path',
        'meta',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'uploaded_at' => 'datetime',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(DispatchTask::class, 'dispatch_task_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
