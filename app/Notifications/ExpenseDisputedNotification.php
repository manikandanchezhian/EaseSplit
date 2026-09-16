<?php

namespace App\Notifications;

use App\Models\ExpenseDispute;
use App\Notifications\Channels\WebPushChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ExpenseDisputedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public ExpenseDispute $dispute) {}

    public function via(object $notifiable): array
    {
        return ['database', WebPushChannel::class];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'expense_disputed',
            'expense_id' => $this->dispute->expense_id,
            'title' => 'Expense dispute created',
            'body' => "{$this->dispute->raisedBy->name} disputed \"{$this->dispute->expense->description}\" ({$this->dispute->reason}).",
        ];
    }

    public function toWebPush(object $notifiable): array
    {
        return [
            'title' => 'Expense dispute created',
            'body' => "{$this->dispute->raisedBy->name} disputed \"{$this->dispute->expense->description}\"",
            'url' => "/app.html?expense={$this->dispute->expense_id}",
        ];
    }
}
