<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Expense;
use App\Models\ExpenseDispute;
use App\Models\ExpenseParticipant;
use App\Models\Group;
use App\Notifications\ExpenseDisputedNotification;
use App\Notifications\SplitCreatedNotification;
use App\Notifications\SplitRespondedNotification;
use App\Services\DebtEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function index(Request $request)
    {
        $query = Expense::query()->with(['payer', 'participants.user'])
            ->where(function ($q) use ($request) {
                $q->where('paid_by', $request->user()->id)
                    ->orWhereHas('participants', fn ($p) => $p->where('user_id', $request->user()->id));
            });

        if ($request->filled('group_id')) {
            $query->where('group_id', $request->integer('group_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json(['expenses' => $query->latest()->paginate(20)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'group_id' => ['nullable', 'integer', 'exists:groups,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'description' => ['required', 'string', 'max:255'],
            'bill_image' => ['nullable', 'string', 'max:2048'],
            'category' => ['nullable', 'string', 'max:100'],
            'paid_by' => ['nullable', 'integer', 'exists:users,id'],
            'split_method' => ['required', 'in:equal,percentage,custom,itemized'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'participants' => ['required', 'array', 'min:1'],
            'participants.*.user_id' => ['required', 'integer', 'exists:users,id', 'distinct'],
            'participants.*.share_amount' => ['nullable', 'numeric', 'min:0'],
            'participants.*.percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'participants.*.quantity' => ['nullable', 'integer', 'min:0'],
        ]);

        $group = null;
        if (! empty($data['group_id'])) {
            $group = Group::findOrFail($data['group_id']);
            abort_unless($group->users()->where('users.id', $request->user()->id)->exists(), 403, 'Not a member of this group.');
        }

        $paidBy = $data['paid_by'] ?? $request->user()->id;
        $shares = $this->computeShares($data['split_method'], (string) $data['amount'], $data['participants']);

        $expense = DB::transaction(function () use ($data, $group, $paidBy, $shares, $request) {
            $expense = Expense::create([
                'group_id' => $group?->id,
                'paid_by' => $paidBy,
                'created_by' => $request->user()->id,
                'amount' => $data['amount'],
                'description' => $data['description'],
                'bill_image' => $data['bill_image'] ?? null,
                'category' => $data['category'] ?? null,
                'split_method' => $data['split_method'],
                'status' => Expense::STATUS_PENDING_APPROVAL,
                'due_date' => $data['due_date'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($shares as $share) {
                $isPayer = (int) $share['user_id'] === (int) $paidBy;

                $expense->participants()->create([
                    'user_id' => $share['user_id'],
                    'share_amount' => $share['share_amount'],
                    'percentage' => $share['percentage'] ?? null,
                    'quantity' => $share['quantity'] ?? null,
                    // The payer's own share is implicitly accepted -- they don't owe themselves.
                    'status' => $isPayer ? ExpenseParticipant::STATUS_ACCEPTED : ExpenseParticipant::STATUS_PENDING,
                    'responded_at' => $isPayer ? now() : null,
                ]);
            }

            return $expense;
        });

        ActivityLog::record('expense_created', "{$request->user()->name} created expense {$expense->description}", $request->user()->id, $group?->id, $expense);

        $expense->load('participants.user', 'payer');
        foreach ($expense->participants as $participant) {
            if ((int) $participant->user_id !== (int) $paidBy) {
                $participant->user->notify(new SplitCreatedNotification($expense));
            }
        }

        return response()->json(['expense' => $expense], 201);
    }

    public function show(Request $request, Expense $expense)
    {
        $this->authorizeParticipantOrPayer($request, $expense);

        return response()->json(['expense' => $expense->load('participants.user', 'payer', 'disputes.raisedBy')]);
    }

    public function respond(Request $request, Expense $expense, DebtEngine $debtEngine)
    {
        $data = $request->validate([
            'action' => ['required', 'in:accept,reject'],
        ]);

        $participant = $expense->participants()->where('user_id', $request->user()->id)->firstOrFail();

        if ($participant->status === ExpenseParticipant::STATUS_ACCEPTED && $data['action'] === 'accept') {
            return response()->json(['participant' => $participant]);
        }

        $payerId = (int) $expense->paid_by;
        $participantUserId = (int) $participant->user_id;
        $isSelf = $payerId === $participantUserId;

        // Snapshot the pairwise balance (from the payer's perspective) both
        // before and after this response, so the notification can explain
        // the actual before/after tally, not just the new total.
        $oldBalance = $isSelf ? '0.00' : $debtEngine->ledgerFor($payerId, $participantUserId)->balanceFor($payerId);

        DB::transaction(function () use ($data, $participant, $debtEngine) {
            $wasAccepted = $participant->status === ExpenseParticipant::STATUS_ACCEPTED;

            $participant->status = $data['action'] === 'accept'
                ? ExpenseParticipant::STATUS_ACCEPTED
                : ExpenseParticipant::STATUS_REJECTED;
            $participant->responded_at = now();
            $participant->save();

            if ($data['action'] === 'accept') {
                $debtEngine->recordExpenseShareAccepted($participant);
            } elseif ($wasAccepted) {
                $debtEngine->reverseExpenseShare($participant);
            }

            $this->recomputeExpenseStatus($participant->expense);
        });

        $newBalance = $isSelf ? '0.00' : $debtEngine->ledgerFor($payerId, $participantUserId)->balanceFor($payerId);

        $expense->refresh()->load('payer', 'creator');
        $notification = new SplitRespondedNotification($participant, (float) $oldBalance, (float) $newBalance);

        // Tell both sides of the balance that actually moved: the payer,
        // and the participant who just responded (skipped only when they
        // are the same person, i.e. the payer accepting their own share).
        $expense->payer->notify($notification);
        if (! $isSelf) {
            $participant->user->notify($notification);
        }
        if ((int) $expense->created_by !== $payerId) {
            $expense->creator->notify($notification);
        }

        return response()->json(['participant' => $participant->fresh(), 'expense' => $expense]);
    }

    public function dispute(Request $request, Expense $expense)
    {
        $data = $request->validate([
            'reason' => ['required', 'in:wrong_amount,wrong_participants,duplicate_expense,other'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->authorizeParticipantOrPayer($request, $expense);

        $dispute = DB::transaction(function () use ($data, $expense, $request) {
            $dispute = ExpenseDispute::create([
                'expense_id' => $expense->id,
                'raised_by' => $request->user()->id,
                'reason' => $data['reason'],
                'description' => $data['description'] ?? null,
                'status' => 'open',
            ]);

            $expense->update(['status' => Expense::STATUS_DISPUTED]);

            $participant = $expense->participants()->where('user_id', $request->user()->id)->first();
            $participant?->update(['status' => ExpenseParticipant::STATUS_DISPUTED, 'responded_at' => now()]);

            return $dispute;
        });

        $expense->creator->notify(new ExpenseDisputedNotification($dispute->load('raisedBy', 'expense')));

        return response()->json(['dispute' => $dispute], 201);
    }

    /**
     * Split $amount across $participants per the requested method.
     *
     * @return array<int, array{user_id:int, share_amount:string, percentage?:string, quantity?:int}>
     */
    private function computeShares(string $method, string $amount, array $participants): array
    {
        return match ($method) {
            'equal' => $this->splitEqual($amount, $participants),
            'percentage' => $this->splitByPercentage($amount, $participants),
            'custom', 'itemized' => $this->splitCustom($amount, $participants),
            default => throw ValidationException::withMessages(['split_method' => 'Unsupported split method.']),
        };
    }

    private function splitEqual(string $amount, array $participants): array
    {
        $count = count($participants);
        $base = bcdiv($amount, (string) $count, 2);
        $allocated = bcmul($base, (string) $count, 2);
        $remainder = bcsub($amount, $allocated, 2);

        return collect($participants)->values()->map(function ($p, $index) use ($base, $remainder, $count) {
            $share = $index === $count - 1 ? bcadd($base, $remainder, 2) : $base;

            return ['user_id' => $p['user_id'], 'share_amount' => $share];
        })->all();
    }

    private function splitByPercentage(string $amount, array $participants): array
    {
        $totalPercentage = collect($participants)->sum(fn ($p) => (float) ($p['percentage'] ?? 0));

        if (abs($totalPercentage - 100.0) > 0.01) {
            throw ValidationException::withMessages(['participants' => 'Percentages must add up to 100.']);
        }

        $shares = [];
        $runningTotal = '0.00';
        $last = count($participants) - 1;

        foreach (array_values($participants) as $index => $p) {
            if ($index === $last) {
                $share = bcsub($amount, $runningTotal, 2);
            } else {
                $share = bcdiv(bcmul($amount, (string) $p['percentage'], 4), '100', 2);
                $runningTotal = bcadd($runningTotal, $share, 2);
            }

            $shares[] = ['user_id' => $p['user_id'], 'share_amount' => $share, 'percentage' => $p['percentage']];
        }

        return $shares;
    }

    private function splitCustom(string $amount, array $participants): array
    {
        $total = collect($participants)->reduce(fn ($carry, $p) => bcadd($carry, (string) ($p['share_amount'] ?? '0'), 2), '0.00');

        if (bccomp($total, $amount, 2) !== 0) {
            throw ValidationException::withMessages(['participants' => "Custom shares (\u{20B9}{$total}) must add up to the total amount (\u{20B9}{$amount})."]);
        }

        return collect($participants)->map(fn ($p) => [
            'user_id' => $p['user_id'],
            'share_amount' => (string) $p['share_amount'],
            'quantity' => $p['quantity'] ?? null,
        ])->all();
    }

    private function recomputeExpenseStatus(Expense $expense): void
    {
        $participants = $expense->participants()->get();
        $nonPayer = $participants->where('user_id', '!=', $expense->paid_by);

        if ($nonPayer->isNotEmpty() && $nonPayer->every(fn ($p) => $p->status === ExpenseParticipant::STATUS_REJECTED)) {
            $expense->update(['status' => Expense::STATUS_REJECTED]);
        } elseif ($nonPayer->contains(fn ($p) => $p->status === ExpenseParticipant::STATUS_DISPUTED)) {
            $expense->update(['status' => Expense::STATUS_DISPUTED]);
        } elseif ($nonPayer->contains(fn ($p) => $p->status === ExpenseParticipant::STATUS_ACCEPTED)) {
            $expense->update(['status' => Expense::STATUS_ACTIVE]);
        }
    }

    private function authorizeParticipantOrPayer(Request $request, Expense $expense): void
    {
        $userId = $request->user()->id;
        $isInvolved = (int) $expense->paid_by === (int) $userId
            || (int) $expense->created_by === (int) $userId
            || $expense->participants()->where('user_id', $userId)->exists();

        abort_unless($isInvolved, 403, 'Not part of this expense.');
    }
}
