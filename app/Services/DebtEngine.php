<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\ExpenseParticipant;
use App\Models\Ledger;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Smart Debt Engine.
 *
 * Every accepted expense share and every verified settlement flows through
 * applyDelta(), which nets straight into a single normalized `ledgers` row
 * per unordered pair of users. There is never more than one balance between
 * any two people — "Ram owes Ajay ₹500" and "Ajay owes Ram ₹300" always
 * collapse into "Ram owes Ajay ₹200".
 */
class DebtEngine
{
    /**
     * Record that $fromUserId owes $toUserId an additional $amount.
     * Negative $amount is allowed and simply nets in the opposite direction.
     */
    public function applyDelta(int $fromUserId, int $toUserId, string $amount): Ledger
    {
        if ($fromUserId === $toUserId || bccomp($amount, '0', 2) === 0) {
            return $this->ledgerFor($fromUserId, $toUserId);
        }

        return DB::transaction(function () use ($fromUserId, $toUserId, $amount) {
            [$userAId, $userBId] = $this->sortedPair($fromUserId, $toUserId);

            $ledger = Ledger::query()
                ->where('user_a_id', $userAId)
                ->where('user_b_id', $userBId)
                ->lockForUpdate()
                ->first();

            if (! $ledger) {
                $ledger = Ledger::create([
                    'user_a_id' => $userAId,
                    'user_b_id' => $userBId,
                    'balance' => '0.00',
                ]);
            }

            // Positive ledger balance means user_b owes user_a.
            // If the debtor (fromUserId) is user_a, then user_a's debt to user_b
            // grows, which moves the balance in user_b's favor (decreases it).
            // If the debtor is user_b, the balance increases (matches the
            // positive-means-user_b-owes-user_a convention).
            $sign = $fromUserId === $userAId ? '-1' : '1';
            $delta = bcmul($amount, $sign, 2);

            $ledger->balance = bcadd((string) $ledger->balance, $delta, 2);
            $ledger->last_activity_at = now();
            $ledger->save();

            return $ledger->refresh();
        });
    }

    public function recordExpenseShareAccepted(ExpenseParticipant $participant): void
    {
        $expense = $participant->expense;

        if ((int) $participant->user_id === (int) $expense->paid_by) {
            return;
        }

        $this->applyDelta((int) $participant->user_id, (int) $expense->paid_by, (string) $participant->share_amount);
    }

    /**
     * Reverse a previously-accepted share (e.g. dispute resolved in the
     * participant's favor, or an accepted expense is later rejected).
     */
    public function reverseExpenseShare(ExpenseParticipant $participant): void
    {
        $expense = $participant->expense;

        if ((int) $participant->user_id === (int) $expense->paid_by) {
            return;
        }

        $this->applyDelta((int) $expense->paid_by, (int) $participant->user_id, (string) $participant->share_amount);
    }

    /**
     * A verified settlement means the payer (from_user) actually paid the
     * recipient (to_user), so the recipient's claim on the payer shrinks.
     */
    public function recordSettlementVerified(Settlement $settlement): void
    {
        $this->applyDelta((int) $settlement->to_user_id, (int) $settlement->from_user_id, (string) $settlement->amount);
    }

    public function ledgerFor(int $userAId, int $userBId): Ledger
    {
        [$a, $b] = $this->sortedPair($userAId, $userBId);

        return Ledger::query()->where('user_a_id', $a)->where('user_b_id', $b)->first()
            ?? new Ledger(['user_a_id' => $a, 'user_b_id' => $b, 'balance' => '0.00']);
    }

    /**
     * Balance signed from $userId's perspective against every counterpart.
     * Positive = they are owed. Negative = they owe.
     */
    public function balancesFor(int $userId): Collection
    {
        return Ledger::query()
            ->where(fn ($q) => $q->where('user_a_id', $userId)->orWhere('user_b_id', $userId))
            ->where('balance', '!=', 0)
            ->get()
            ->map(fn (Ledger $ledger) => [
                'user_id' => $ledger->otherUserId($userId),
                'balance' => $ledger->balanceFor($userId),
                'ledger' => $ledger,
            ]);
    }

    public function dashboardSummary(User $user): array
    {
        $balances = $this->balancesFor($user->id);

        $youWillReceive = $balances->filter(fn ($b) => bccomp($b['balance'], '0', 2) > 0)
            ->reduce(fn ($carry, $b) => bcadd($carry, $b['balance'], 2), '0.00');

        $youOwe = $balances->filter(fn ($b) => bccomp($b['balance'], '0', 2) < 0)
            ->reduce(fn ($carry, $b) => bcadd($carry, bcmul($b['balance'], '-1', 2), 2), '0.00');

        return [
            'you_owe' => $youOwe,
            'you_will_receive' => $youWillReceive,
            'pending_settlements' => $balances->count(),
            'active_groups' => $user->groups()->whereNull('left_at')->count(),
        ];
    }

    /**
     * @return array{owed_to_you: Collection, you_owe: Collection}
     */
    public function friendBreakdown(User $user): array
    {
        $balances = $this->balancesFor($user->id)->sortByDesc(fn ($b) => abs((float) $b['balance']));

        $userIds = $balances->pluck('user_id')->all();
        $users = User::query()->whereIn('id', $userIds)->get()->keyBy('id');

        $owedToYou = $balances->filter(fn ($b) => bccomp($b['balance'], '0', 2) > 0)
            ->map(fn ($b) => [
                'user' => $users->get($b['user_id']),
                'amount' => $b['balance'],
            ])->values();

        $youOwe = $balances->filter(fn ($b) => bccomp($b['balance'], '0', 2) < 0)
            ->map(fn ($b) => [
                'user' => $users->get($b['user_id']),
                'amount' => bcmul($b['balance'], '-1', 2),
            ])->values();

        return ['owed_to_you' => $owedToYou, 'you_owe' => $youOwe];
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function sortedPair(int $userAId, int $userBId): array
    {
        return $userAId < $userBId ? [$userAId, $userBId] : [$userBId, $userAId];
    }
}
