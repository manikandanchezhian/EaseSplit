<?php

namespace App\Notifications;

use App\Models\Expense;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SplitCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Expense $expense) {}

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'split_created',
            'expense_id' => $this->expense->id,
            'title' => 'New split pending your approval',
            'body' => $this->body($notifiable),
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => 'New split pending your approval',
            'body' => $this->body($notifiable),
            'url' => "/app.html?expense={$this->expense->id}",
        ];
    }

    private function body(object $notifiable): string
    {
        $total = number_format((float) $this->expense->amount, 2);
        $mine = $this->expense->participants->firstWhere('user_id', $notifiable->id);
        $share = $mine ? number_format((float) $mine->share_amount, 2) : null;

        $line = "{$this->expense->payer->name} added \u{20B9}{$total} for {$this->expense->description}.";

        return $share ? "{$line} Your share is \u{20B9}{$share}." : $line;
    }
}
