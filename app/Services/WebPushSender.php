<?php

namespace App\Services;

use App\Models\PushSubscription;
use App\Models\User;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushSender
{
    private ?WebPush $client = null;

    public function sendToUser(User $user, array $payload): void
    {
        $subscriptions = $user->pushSubscriptions;

        if ($subscriptions->isEmpty()) {
            return;
        }

        $client = $this->client();
        $json = json_encode($payload);

        foreach ($subscriptions as $subscription) {
            $client->queueNotification(
                Subscription::create([
                    'endpoint' => $subscription->endpoint,
                    'keys' => [
                        'p256dh' => $subscription->public_key,
                        'auth' => $subscription->auth_token,
                    ],
                ]),
                $json
            );
        }

        foreach ($client->flush() as $report) {
            if (! $report->isSuccess() && $report->isSubscriptionExpired()) {
                PushSubscription::where('endpoint', $report->getEndpoint())->delete();
            }
        }
    }

    private function client(): WebPush
    {
        if ($this->client) {
            return $this->client;
        }

        $publicKey = config('services.webpush.public_key');
        $privateKey = config('services.webpush.private_key');

        if (! $publicKey || ! $privateKey) {
            throw new \RuntimeException('VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY are not configured.');
        }

        return $this->client = new WebPush([
            'VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ],
        ]);
    }
}
