<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\PaymentVerification;
use App\Models\Settlement;
use App\Models\User;
use App\Notifications\SettlementVerifiedNotification;
use App\Services\DebtEngine;
use App\Services\UpiLinkBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SettlementController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;

        $settlements = Settlement::query()
            ->where(fn ($q) => $q->where('from_user_id', $userId)->orWhere('to_user_id', $userId))
            ->with('fromUser', 'toUser', 'group')
            ->latest()
            ->paginate(20);

        return response()->json(['settlements' => $settlements]);
    }

    public function store(Request $request, UpiLinkBuilder $upiLinkBuilder)
    {
        $data = $request->validate([
            'to_user_id' => ['required', 'integer', 'exists:users,id', 'different:from_user_id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'method' => ['nullable', 'in:upi,cash,other'],
            'upi_app' => ['nullable', 'in:gpay,phonepe,paytm,bhim,other'],
        ]);

        $toUser = User::findOrFail($data['to_user_id']);

        $settlement = Settlement::create([
            'from_user_id' => $request->user()->id,
            'to_user_id' => $toUser->id,
            'group_id' => $data['group_id'] ?? null,
            'amount' => $data['amount'],
            'method' => $data['method'] ?? 'upi',
            'upi_app' => $data['upi_app'] ?? null,
            'status' => Settlement::STATUS_INITIATED,
            'reference_id' => 'ES-'.Str::upper(Str::random(10)),
            'initiated_at' => now(),
        ]);

        $payload = [
            'settlement' => $settlement,
        ];

        if (($data['method'] ?? 'upi') === 'upi') {
            $payload['upi_deep_link'] = $upiLinkBuilder->build($settlement, $toUser);
            $payload['payee_upi_available'] = (bool) $toUser->upi_id;
        }

        ActivityLog::record('settlement_initiated', "{$request->user()->name} initiated payment of \u{20B9}{$settlement->amount} to {$toUser->name}", $request->user()->id, $settlement->group_id, $settlement);

        return response()->json($payload, 201);
    }

    public function show(Request $request, Settlement $settlement)
    {
        $this->authorizeParty($request, $settlement);

        return response()->json(['settlement' => $settlement->load('fromUser', 'toUser', 'verifications')]);
    }

    /**
     * Records the outcome of a payment verification attempt.
     *
     * In production this is called by a signed webhook from the UPI
     * Collect provider / payment gateway (Method 1-3 in the spec), or by a
     * scheduled bank-statement reconciliation job (Method 4) -- not
     * directly by the paying user. It is exposed here as an authenticated
     * user action so the MVP can be exercised end-to-end without a live
     * payment gateway integration.
     */
    public function verify(Request $request, Settlement $settlement, DebtEngine $debtEngine)
    {
        $this->authorizeParty($request, $settlement);

        $data = $request->validate([
            'status' => ['required', 'in:verified,failed,processing'],
            'method' => ['required', 'in:upi_collect,upi_deep_link,payment_gateway,bank_reconciliation'],
            'provider' => ['nullable', 'string', 'max:100'],
            'provider_reference' => ['nullable', 'string', 'max:255'],
            'raw_response' => ['nullable', 'array'],
        ]);

        abort_if(in_array($settlement->status, [Settlement::STATUS_VERIFIED, Settlement::STATUS_FAILED], true), 422, 'Settlement is already finalized.');

        // Snapshot the pairwise balance (from the payer's perspective)
        // before this verification, so the notification can show the
        // actual before/after tally instead of just the new total.
        $oldBalance = $debtEngine->ledgerFor((int) $settlement->from_user_id, (int) $settlement->to_user_id)
            ->balanceFor((int) $settlement->from_user_id);

        DB::transaction(function () use ($data, $settlement, $debtEngine) {
            PaymentVerification::create([
                'settlement_id' => $settlement->id,
                'method' => $data['method'],
                'provider' => $data['provider'] ?? null,
                'provider_reference' => $data['provider_reference'] ?? null,
                'amount' => $settlement->amount,
                'status' => $data['status'],
                'raw_response' => $data['raw_response'] ?? null,
                'verified_at' => $data['status'] === 'verified' ? now() : null,
            ]);

            $settlement->status = $data['status'];
            if ($data['status'] === 'verified') {
                $settlement->verified_at = now();
            }
            $settlement->save();

            if ($data['status'] === 'verified') {
                $debtEngine->recordSettlementVerified($settlement);
            }
        });

        if ($settlement->status === Settlement::STATUS_VERIFIED) {
            $settlement->load('fromUser', 'toUser');

            $newBalance = $debtEngine->ledgerFor((int) $settlement->from_user_id, (int) $settlement->to_user_id)
                ->balanceFor((int) $settlement->from_user_id);

            $notification = new SettlementVerifiedNotification($settlement, (float) $oldBalance, (float) $newBalance);

            // Both sides of the balance that moved get told, with the tally:
            // the payer (who didn't trigger this request) and the recipient
            // (who did, but benefits from the same explicit before/after).
            $settlement->fromUser->notify($notification);
            $settlement->toUser->notify($notification);

            ActivityLog::record('settlement_verified', "{$settlement->fromUser->name} paid \u{20B9}{$settlement->amount} to {$settlement->toUser->name}", $settlement->from_user_id, $settlement->group_id, $settlement);
        }

        return response()->json(['settlement' => $settlement->fresh()->load('verifications')]);
    }

    private function authorizeParty(Request $request, Settlement $settlement): void
    {
        $userId = $request->user()->id;
        abort_unless(in_array($userId, [$settlement->from_user_id, $settlement->to_user_id], true), 403, 'Not part of this settlement.');
    }
}
