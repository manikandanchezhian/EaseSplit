<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Group;
use App\Models\Settlement;
use App\Models\User;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['q' => ['required', 'string', 'min:1', 'max:100']]);
        $q = $data['q'];
        $userId = $request->user()->id;

        $people = User::query()
            ->where(fn ($query) => $query->where('name', 'like', "%{$q}%")
                ->orWhere('username', 'like', "%{$q}%")
                ->orWhere('email', 'like', "%{$q}%"))
            ->limit(10)->get(['id', 'name', 'username', 'email', 'avatar']);

        $groups = Group::query()
            ->whereHas('users', fn ($query) => $query->where('users.id', $userId))
            ->where('name', 'like', "%{$q}%")
            ->limit(10)->get();

        $expenses = Expense::query()
            ->where(function ($query) use ($userId) {
                $query->where('paid_by', $userId)
                    ->orWhereHas('participants', fn ($p) => $p->where('user_id', $userId));
            })
            ->where(fn ($query) => $query->where('description', 'like', "%{$q}%")->orWhere('notes', 'like', "%{$q}%"))
            ->limit(10)->get();

        $settlements = Settlement::query()
            ->where(fn ($query) => $query->where('from_user_id', $userId)->orWhere('to_user_id', $userId))
            ->where('reference_id', 'like', "%{$q}%")
            ->limit(10)->get();

        return response()->json([
            'people' => $people,
            'groups' => $groups,
            'expenses' => $expenses,
            'settlements' => $settlements,
        ]);
    }
}
