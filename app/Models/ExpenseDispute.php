<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExpenseDispute extends Model
{
    public const REASON_WRONG_AMOUNT = 'wrong_amount';

    public const REASON_WRONG_PARTICIPANTS = 'wrong_participants';

    public const REASON_DUPLICATE_EXPENSE = 'duplicate_expense';

    public const REASON_OTHER = 'other';

    protected $fillable = [
        'expense_id', 'raised_by', 'reason', 'description',
        'status', 'resolved_by', 'resolution_notes', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
