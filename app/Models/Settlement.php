<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Settlement extends Model
{
    public const STATUS_INITIATED = 'initiated';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'from_user_id', 'to_user_id', 'group_id', 'amount', 'method',
        'upi_app', 'status', 'reference_id', 'initiated_at', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'initiated_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function verification(): HasOne
    {
        return $this->hasOne(PaymentVerification::class)->latestOfMany();
    }

    public function verifications(): HasMany
    {
        return $this->hasMany(PaymentVerification::class);
    }
}
