<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Settlement;
use App\Models\User;
use App\Services\DebtEngine;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GroupController extends Controller
{
    public function index(Request $request)
    {
        $groups = $request->user()->groups()
            ->whereNull('left_at')
            ->with('group.users')
            ->get()
            ->pluck('group');

        return response()->json(['groups' => $groups]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'image' => ['nullable', 'string', 'max:2048'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['nullable', 'in:house,trip,roommates,office,event,family,team'],
            'member_ids' => ['array'],
            'member_ids.*' => ['integer', 'exists:users,id'],
            'usernames' => ['array'],
            'usernames.*' => ['string'],
        ]);

        $group = Group::create([
            'name' => $data['name'],
            'image' => $data['image'] ?? null,
            'description' => $data['description'] ?? null,
            'type' => $data['type'] ?? 'house',
            'created_by' => $request->user()->id,
            'invite_code' => Str::upper(Str::random(8)),
        ]);

        $notFound = [];
        $memberIds = collect($data['member_ids'] ?? []);

        foreach ($data['usernames'] ?? [] as $username) {
            $user = User::where('username', Str::lower(trim($username)))->first();
            if ($user) {
                $memberIds->push($user->id);
            } else {
                $notFound[] = $username;
            }
        }

        $memberIds = $memberIds->push($request->user()->id)->unique();

        foreach ($memberIds as $userId) {
            GroupMember::create([
                'group_id' => $group->id,
                'user_id' => $userId,
                'role' => $userId === $request->user()->id ? 'admin' : 'member',
            ]);
        }

        ActivityLog::record('group_created', "{$request->user()->name} created group {$group->name}", $request->user()->id, $group->id, $group);

        return response()->json(['group' => $group->load('users'), 'not_found_usernames' => $notFound], 201);
    }

    public function show(Request $request, Group $group)
    {
        $this->authorizeMember($request, $group);

        return response()->json(['group' => $group->load('users', 'creator')]);
    }

    public function update(Request $request, Group $group)
    {
        $this->authorizeAdmin($request, $group);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'image' => ['nullable', 'string', 'max:2048'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['sometimes', 'in:house,trip,roommates,office,event,family,team'],
        ]);

        $group->update($data);

        return response()->json(['group' => $group]);
    }

    public function destroy(Request $request, Group $group)
    {
        $this->authorizeAdmin($request, $group);
        $group->update(['archived_at' => now()]);

        return response()->json(['message' => 'Group archived.']);
    }

    public function addMembers(Request $request, Group $group)
    {
        $this->authorizeMember($request, $group);

        $data = $request->validate([
            'member_ids' => ['array'],
            'member_ids.*' => ['integer', 'exists:users,id'],
            'usernames' => ['array'],
            'usernames.*' => ['string'],
        ]);

        if (empty($data['member_ids']) && empty($data['usernames'])) {
            throw ValidationException::withMessages(['usernames' => 'Provide at least one member.']);
        }

        $notFound = [];
        $userIds = collect($data['member_ids'] ?? []);

        foreach ($data['usernames'] ?? [] as $username) {
            $user = User::where('username', Str::lower(trim($username)))->first();
            if ($user) {
                $userIds->push($user->id);
            } else {
                $notFound[] = $username;
            }
        }

        $existing = $group->users()->pluck('users.id')->all();

        foreach ($userIds->unique() as $userId) {
            if (in_array($userId, $existing, true)) {
                continue;
            }

            GroupMember::create(['group_id' => $group->id, 'user_id' => $userId, 'role' => 'member']);
        }

        return response()->json([
            'group' => $group->fresh()->load('users'),
            'not_found_usernames' => $notFound,
        ]);
    }

    public function join(Request $request)
    {
        $data = $request->validate(['invite_code' => ['required', 'string']]);

        $group = Group::where('invite_code', Str::upper($data['invite_code']))->first();

        if (! $group) {
            throw ValidationException::withMessages(['invite_code' => 'Invalid invite code.']);
        }

        GroupMember::firstOrCreate(
            ['group_id' => $group->id, 'user_id' => $request->user()->id],
            ['role' => 'member']
        );

        return response()->json(['group' => $group->load('users')]);
    }

    public function ledger(Request $request, Group $group, DebtEngine $debtEngine)
    {
        $this->authorizeMember($request, $group);

        $memberIds = $group->users()->pluck('users.id');

        $expenses = $group->expenses()->with('participants.user', 'payer')->latest()->get();
        $settlements = $group->settlements()->with('fromUser', 'toUser')
            ->where('status', Settlement::STATUS_VERIFIED)->latest()->get();

        $contributions = $memberIds->mapWithKeys(function ($userId) use ($expenses) {
            $paid = $expenses->where('paid_by', $userId)->sum('amount');

            return [$userId => $paid];
        });

        return response()->json([
            'group' => $group,
            'expenses' => $expenses,
            'settlements' => $settlements,
            'member_contributions' => $contributions,
        ]);
    }

    private function authorizeMember(Request $request, Group $group): void
    {
        abort_unless($group->users()->where('users.id', $request->user()->id)->exists(), 403, 'Not a member of this group.');
    }

    private function authorizeAdmin(Request $request, Group $group): void
    {
        $member = $group->members()->where('user_id', $request->user()->id)->whereNull('left_at')->first();
        abort_unless($member && $member->role === 'admin', 403, 'Only group admins can do this.');
    }
}
