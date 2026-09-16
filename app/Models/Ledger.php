<?php

namespace App\Models;

use App\Services\DebtEngine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single normalized balance row per unordered pair of users.
 *
 * @see DebtEngine
 */
class Ledger extends Model
{
    protected $fillable = [
        'user_a_id', 'user_b_id', 'balance', 'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'last_activity_at' => 'datetime',
        ];
    }

    public function userA(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_a_id');
    }

    public function userB(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_b_id');
    }

    /**
     * Balance signed from the perspective of the given user id.
     * Positive = this user is owed money. Negative = this user owes money.
     */
    public function balanceFor(int $userId): string
    {
        if ((int) $this->user_a_id === $userId) {
            return (string) $this->balance;
        }

        return bcmul((string) $this->balance, '-1', 2);
    }

    public function otherUserId(int $userId): int
    {
        return (int) $this->user_a_id === $userId ? (int) $this->user_b_id : (int) $this->user_a_id;
    }
}
