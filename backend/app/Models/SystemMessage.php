<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'message_type',
        'title',
        'content',
        'meta',
        'is_pinned',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'is_pinned' => 'boolean',
            'read_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
