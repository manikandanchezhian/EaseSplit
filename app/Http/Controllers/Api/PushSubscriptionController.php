<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use Illuminate\Http\Request;

class PushSubscriptionController extends Controller
{
    /**
     * Store (or refresh) a browser PushSubscription for the current user.
     * Expects the shape of PushSubscription.toJSON() from the frontend:
     * { endpoint, keys: { p256dh, auth } }.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:512'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'device_label' => ['nullable', 'string', 'max:100'],
        ]);

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            [
                'user_id' => $request->user()->id,
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'device_label' => $data['device_label'] ?? null,
            ]
        );

        return response()->json(['subscription' => $subscription], 201);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:512']]);

        $request->user()->pushSubscriptions()->where('endpoint', $data['endpoint'])->delete();

        return response()->json(['message' => 'Unsubscribed.']);
    }
}
