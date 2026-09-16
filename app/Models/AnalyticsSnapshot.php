<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsSnapshot extends Model
{
    protected $fillable = [
        'user_id', 'group_id', 'period_type', 'period_start', 'period_end',
        'total_spent', 'total_paid', 'total_owed', 'total_received',
        'category_breakdown', 'friend_breakdown',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_spent' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'total_owed' => 'decimal:2',
            'total_received' => 'decimal:2',
            'category_breakdown' => 'array',
            'friend_breakdown' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
