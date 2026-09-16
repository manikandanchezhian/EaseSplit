<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\DebtEngine;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, DebtEngine $debtEngine)
    {
        $user = $request->user();
        $summary = $debtEngine->dashboardSummary($user);
        $breakdown = $debtEngine->friendBreakdown($user);

        $recentActivity = ActivityLog::query()
            ->where('user_id', $user->id)
            ->orWhereIn('group_id', $user->groups()->whereNull('left_at')->pluck('group_id'))
            ->latest()
            ->limit(15)
            ->get();

        return response()->json([
            'cards' => [
                'you_owe' => $summary['you_owe'],
                'you_will_receive' => $summary['you_will_receive'],
                'pending_settlements' => $summary['pending_settlements'],
                'active_groups' => $summary['active_groups'],
            ],
            'people_who_owe_you' => $breakdown['owed_to_you'],
            'people_you_owe' => $breakdown['you_owe'],
            'recent_activity' => $recentActivity,
        ]);
    }
}
