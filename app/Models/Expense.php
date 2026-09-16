<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Expense extends Model
{
    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_DISPUTED = 'disputed';

    public const STATUS_SETTLED = 'settled';

    protected $fillable = [
        'group_id', 'paid_by', 'created_by', 'amount', 'description',
        'bill_image', 'category', 'split_method', 'status', 'due_date', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_date' => 'date',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ExpenseParticipant::class);
    }

    public function disputes(): HasMany
    {
        return $this->hasMany(ExpenseDispute::class);
    }
}
