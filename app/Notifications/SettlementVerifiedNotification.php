<?php

namespace App\Notifications;

use App\Models\Settlement;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SettlementVerifiedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * $oldBalance / $newBalance are the pairwise ledger balance between the
     * two parties, from the payer's (from_user's) perspective, snapshotted
     * immediately before and after this settlement was verified. render()
     * flips the sign for the recipient's own perspective.
     */
    public function __construct(
        public Settlement $settlement,
        public float $oldBalance = 0.0,
        public float $newBalance = 0.0,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        [$title, $body] = $this->render($notifiable);

        return [
            'type' => 'settlement_verified',
            'settlement_id' => $this->settlement->id,
            'title' => $title,
            'body' => $body,
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        [$title, $body] = $this->render($notifiable);

        return ['title' => $title, 'body' => $body, 'url' => '/app.html'];
    }

    /**
     * @return array{0: string, 1: string} [title, body]
     */
    private function render(object $notifiable): array
    {
        $amount = number_format((float) $this->settlement->amount, 2);
        $isPayer = (int) $notifiable->id === (int) $this->settlement->from_user_id;

        $title = $isPayer
            ? "{$this->settlement->toUser->name} confirmed your \u{20B9}{$amount} payment"
            : "You confirmed {$this->settlement->fromUser->name}'s \u{20B9}{$amount} payment";

        $sign = $isPayer ? 1 : -1;
        $old = $this->oldBalance * $sign;
        $new = $this->newBalance * $sign;
        $otherName = $isPayer ? $this->settlement->toUser->name : $this->settlement->fromUser->name;

        $body = "Balance with {$otherName}: {$this->amountLabel($old)} \u{2192} {$this->amountLabel($new)}.";

        return [$title, $body];
    }

    /**
     * A balance snapshot in plain words, from the perspective of whoever
     * this amount belongs to: positive = the other person owes them.
     */
    private function amountLabel(float $balance): string
    {
        $amount = number_format(abs($balance), 2);

        if ($balance > 0.004) {
            return "they owe you \u{20B9}{$amount}";
        }

        if ($balance < -0.004) {
            return "you owe them \u{20B9}{$amount}";
        }

        return 'settled up';
    }
}
