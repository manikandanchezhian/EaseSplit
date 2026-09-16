<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentVerification extends Model
{
    public const METHOD_UPI_COLLECT = 'upi_collect';

    public const METHOD_UPI_DEEP_LINK = 'upi_deep_link';

    public const METHOD_PAYMENT_GATEWAY = 'payment_gateway';

    public const METHOD_BANK_RECONCILIATION = 'bank_reconciliation';

    protected $fillable = [
        'settlement_id', 'method', 'provider', 'provider_reference',
        'amount', 'status', 'raw_response', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'raw_response' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }
}
