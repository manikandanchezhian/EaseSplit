<?php

namespace App\Services;

use App\Models\Settlement;
use App\Models\User;

/**
 * Builds a standard UPI Deep Link (upi://pay?...) that any UPI app
 * (Google Pay, PhonePe, Paytm, BHIM, ...) can open to prefill a payment.
 * Clicking "Pay" alone is not proof of payment -- see SettlementController::verify().
 */
class UpiLinkBuilder
{
    /**
     * Returns null if the payee hasn't set a UPI ID -- there is
     * deliberately no "default" VPA to fall back to here, since that
     * would silently send money to whoever's VPA that fallback happened
     * to be instead of the actual payee.
     */
    public function build(Settlement $settlement, User $payee): ?string
    {
        if (! $payee->upi_id) {
            return null;
        }

        $params = [
            'pa' => $payee->upi_id,
            'pn' => $payee->name,
            'am' => number_format((float) $settlement->amount, 2, '.', ''),
            'cu' => 'INR',
            'tr' => $settlement->reference_id,
            'tn' => "Easesplit settlement #{$settlement->id}",
        ];

        return 'upi://pay?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }
}
