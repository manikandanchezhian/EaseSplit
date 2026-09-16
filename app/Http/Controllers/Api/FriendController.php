<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Friend;
use App\Models\Settlement;
use App\Models\User;
use App\Services\DebtEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FriendController extends Controller
{
    public function index(Request $request, DebtEngine $debtEngine)
    {
        $userId = $request->user()->id;

        $addedFriends = Friend::query()
            ->where('status', 'accepted')
            ->where(fn ($q) => $q->where('user_id', $userId)->orWhere('friend_id', $userId))
            ->with('user', 'friend')
            ->get()
            ->map(fn (Friend $f) => (int) $f->user_id === $userId ? $f->friend : $f->user);

        // Anyone with a live balance (almost always housemates from a
        // shared house) counts as a "friend" here too, whether or not they
        // went through the formal add-friend flow -- that's what actually
        // makes the Friends tab useful for a house.
        $balances = $debtEngine->balancesFor($userId)->keyBy('user_id');
        $balanceUsers = $balances->isEmpty()
            ? collect()
            : User::query()->whereIn('id', $balances->keys())->get();

        $friends = $addedFriends->concat($balanceUsers)
            ->unique('id')
            ->map(function (User $friend) use ($balances) {
                $friend->setAttribute('balance', isset($balances[$friend->id]) ? $balances[$friend->id]['balance'] : '0.00');

                return $friend;
            })
            ->sortByDesc(fn (User $friend) => abs((float) $friend->balance))
            ->values();

        return response()->json(['friends' => $friends]);
    }

    public function pending(Request $request)
    {
        $requests = Friend::query()
            ->where('friend_id', $request->user()->id)
            ->where('status', 'pending')
            ->with('user')
            ->get();

        return response()->json(['pending_requests' => $requests]);
    }

    public function request(Request $request)
    {
        $data = $request->validate([
            'friend_id' => ['required_without:email', 'integer', 'exists:users,id', 'different:user_id'],
            'email' => ['required_without:friend_id', 'email'],
        ]);

        $friend = isset($data['friend_id'])
            ? User::findOrFail($data['friend_id'])
            : User::where('email', $data['email'])->firstOrFail();

        if ($friend->id === $request->user()->id) {
            throw ValidationException::withMessages(['friend_id' => 'You cannot friend yourself.']);
        }

        $existing = Friend::where(fn ($q) => $q->where('user_id', $request->user()->id)->where('friend_id', $friend->id))
            ->orWhere(fn ($q) => $q->where('user_id', $friend->id)->where('friend_id', $request->user()->id))
            ->first();

        if ($existing) {
            return response()->json(['friend_request' => $existing]);
        }

        $friendRequest = Friend::create([
            'user_id' => $request->user()->id,
            'friend_id' => $friend->id,
            'status' => 'pending',
            'requested_by' => $request->user()->id,
        ]);

        return response()->json(['friend_request' => $friendRequest], 201);
    }

    public function respond(Request $request, Friend $friendRequest)
    {
        abort_unless((int) $friendRequest->friend_id === (int) $request->user()->id, 403);

        $data = $request->validate(['action' => ['required', 'in:accept,reject']]);

        $friendRequest->update([
            'status' => $data['action'] === 'accept' ? 'accepted' : 'rejected',
            'responded_at' => now(),
        ]);

        return response()->json(['friend_request' => $friendRequest]);
    }

    public function ledger(Request $request, User $friend, DebtEngine $debtEngine)
    {
        $userId = $request->user()->id;

        $expensesBetween = fn ($payerId) => DB::table('expenses')
            ->join('expense_participants', 'expense_participants.expense_id', '=', 'expenses.id')
            ->where('expenses.paid_by', $payerId)
            ->where('expense_participants.user_id', $payerId === $userId ? $friend->id : $userId)
            ->whereIn('expense_participants.status', ['accepted'])
            ->sum('expense_participants.share_amount');

        $totalPaidByMe = $expensesBetween($userId);
        $totalPaidByThem = $expensesBetween($friend->id);

        $ledger = $debtEngine->ledgerFor($userId, $friend->id);
        $netBalance = $ledger->balanceFor($userId);

        $pendingSettlements = Settlement::where(function ($q) use ($userId, $friend) {
            $q->where(function ($q2) use ($userId, $friend) {
                $q2->where('from_user_id', $userId)->where('to_user_id', $friend->id);
            })->orWhere(function ($q2) use ($userId, $friend) {
                $q2->where('from_user_id', $friend->id)->where('to_user_id', $userId);
            });
        })->whereIn('status', [Settlement::STATUS_INITIATED, Settlement::STATUS_PROCESSING])->count();

        $expenseHistory = Expense::query()
            ->whereHas('participants', fn ($q) => $q->whereIn('user_id', [$userId, $friend->id]))
            ->where(fn ($q) => $q->where('paid_by', $userId)->orWhere('paid_by', $friend->id))
            ->with('participants', 'payer')
            ->latest()
            ->limit(50)
            ->get();

        $settlementHistory = Settlement::where(function ($q) use ($userId, $friend) {
            $q->where('from_user_id', $userId)->where('to_user_id', $friend->id);
        })->orWhere(function ($q) use ($userId, $friend) {
            $q->where('from_user_id', $friend->id)->where('to_user_id', $userId);
        })->latest()->limit(50)->get();

        return response()->json([
            'friend' => $friend,
            'total_paid_by_me' => $totalPaidByMe,
            'total_paid_by_them' => $totalPaidByThem,
            'net_balance' => $netBalance,
            'pending_settlements' => $pendingSettlements,
            'expense_history' => $expenseHistory,
            'settlement_history' => $settlementHistory,
        ]);
    }
}
