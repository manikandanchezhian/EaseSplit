<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Reminder extends Model
{
    public const TYPE_DUE_SOON = 'due_soon';

    public const TYPE_DUE_TODAY = 'due_today';

    public const TYPE_OVERDUE_3 = 'overdue_3';

    public const TYPE_OVERDUE_7 = 'overdue_7';

    public const TYPE_OVERDUE_30 = 'overdue_30';

    protected $fillable = [
        'expense_id', 'settlement_id', 'user_id', 'type', 'channel',
        'scheduled_for', 'sent_at', 'status',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
