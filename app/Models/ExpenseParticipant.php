<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseParticipant extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DISPUTED = 'disputed';

    protected $fillable = [
        'expense_id', 'user_id', 'share_amount', 'percentage', 'quantity', 'status', 'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'share_amount' => 'decimal:2',
            'percentage' => 'decimal:2',
            'responded_at' => 'datetime',
        ];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
