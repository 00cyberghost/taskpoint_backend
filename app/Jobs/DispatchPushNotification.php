<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\FirebaseMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchPushNotification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Notification $notification,
    ) {}

    public function handle(FirebaseMessagingService $firebaseMessagingService): void
    {
        $firebaseMessagingService->sendNotification($this->notification->fresh('user.pushTokens'));
    }
}
