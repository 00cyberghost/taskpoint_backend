<?php

namespace App\Services;

use App\Jobs\DispatchPushNotification;
use App\Jobs\DispatchTopicPushNotification;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Support\Collection;

class NotificationService
{
    public function create(
        User|int $user,
        string $type,
        string $title,
        string $body,
        array $data = [],
        string $channel = 'push',
        string $deliveryStatus = 'queued',
        ?string $imagePath = null,
        bool $dispatch = true,
    ): Notification {
        $notification = Notification::query()->create([
            'user_id' => $user instanceof User ? $user->id : $user,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'image_path' => $imagePath,
            'data_json' => $data === [] ? null : $data,
            'channel' => $channel,
            'sent_at' => $deliveryStatus === 'queued' ? null : now(),
            'delivery_status' => $deliveryStatus,
        ]);

        if ($channel === 'push' && $dispatch) {
            $this->deliverToUser($notification);
        }

        return $notification;
    }

    /**
     * @param  iterable<User>  $users
     * @return Collection<int, Notification>
     */
    public function broadcast(
        iterable $users,
        string $type,
        string $title,
        string $body,
        array $data = [],
        string $channel = 'push',
        string $deliveryStatus = 'queued',
        ?string $imagePath = null,
        ?string $topic = null,
    ): Collection {
        $notifications = collect();

        foreach ($users as $user) {
            $notifications->push(
                $this->create(
                    $user,
                    $type,
                    $title,
                    $body,
                    $data,
                    $channel,
                    $deliveryStatus,
                    $imagePath,
                    dispatch: $topic === null,
                ),
            );
        }

        if ($topic !== null && $channel === 'push' && $notifications->isNotEmpty()) {
            $representative = $notifications->first();
            $notificationIds = $notifications->pluck('id')->map(static fn ($id) => (int) $id)->all();

            if (config('services.notifications.queue_enabled')) {
                DispatchTopicPushNotification::dispatch($representative, $topic, $notificationIds);
            } else {
                app(FirebaseMessagingService::class)->sendTopicNotification(
                    $representative,
                    $topic,
                    $notificationIds,
                );
            }
        }

        return $notifications;
    }

    private function deliverToUser(Notification $notification): void
    {
        if (config('services.notifications.queue_enabled')) {
            DispatchPushNotification::dispatch($notification);

            return;
        }

        app(FirebaseMessagingService::class)->sendNotification($notification->fresh('user.pushTokens'));
    }
}
