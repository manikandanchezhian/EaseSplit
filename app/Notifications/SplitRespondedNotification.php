<?php

namespace App\Notifications;

use App\Models\ExpenseParticipant;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SplitRespondedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * $oldBalance / $newBalance are the pairwise ledger balance between the
     * payer and the responding participant, from the *payer's* perspective,
     * snapshotted immediately before and after this response was applied.
     * render() flips the sign for the participant's own perspective.
     */
    public function __construct(
        public ExpenseParticipant $participant,
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
            'type' => 'split_responded',
            'expense_id' => $this->participant->expense_id,
            'title' => $title,
            'body' => $body,
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        [$title, $body] = $this->render($notifiable);

        return [
            'title' => $title,
            'body' => $body,
            'url' => "/app.html?expense={$this->participant->expense_id}",
        ];
    }

    /**
     * @return array{0: string, 1: string} [title, body]
     */
    private function render(object $notifiable): array
    {
        $expense = $this->participant->expense;
        $payerId = (int) $expense->paid_by;
        $participantUserId = (int) $this->participant->user_id;
        $notifiableId = (int) $notifiable->id;
        $isParticipant = $notifiableId === $participantUserId;
        $isPayer = $notifiableId === $payerId;

        $actorName = $isParticipant ? 'You' : $this->participant->user->name;
        $amount = number_format((float) $this->participant->share_amount, 2);
        $title = "{$actorName} {$this->verb()} \"{$expense->description}\" (\u{20B9}{$amount})";

        // A creator who is neither the payer nor the participant (created
        // the split on the payer's behalf) has no direct balance stake in
        // this pair -- just report the event, not a tally that isn't theirs.
        if (! $isParticipant && ! $isPayer) {
            return [$title, $expense->description];
        }

        if ($this->participant->status !== 'accepted') {
            return [$title, 'No balance change.'];
        }

        $sign = $isPayer ? 1 : -1;
        $old = $this->oldBalance * $sign;
        $new = $this->newBalance * $sign;
        $otherName = $isPayer ? $this->participant->user->name : $expense->payer->name;

        $body = "Balance with {$otherName}: {$this->amountLabel($old)} \u{2192} {$this->amountLabel($new)}.";

        return [$title, $body];
    }

    private function verb(): string
    {
        return match ($this->participant->status) {
            'accepted' => 'accepted',
            'rejected' => 'rejected',
            default => 'responded to',
        };
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
