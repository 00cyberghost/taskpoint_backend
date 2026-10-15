<?php

namespace App\Jobs;

use App\Models\Notification;
use App\Services\FirebaseMessagingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchTopicPushNotification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, int>  $notificationIds
     */
    public function __construct(
        public Notification $notification,
        public string $topic,
        public array $notificationIds,
    ) {}

    public function handle(FirebaseMessagingService $firebaseMessagingService): void
    {
        $firebaseMessagingService->sendTopicNotification(
            $this->notification->fresh(),
            $this->topic,
            $this->notificationIds,
        );
    }
}
