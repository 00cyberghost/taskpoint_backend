<?php

namespace App\Services;

use App\Jobs\DispatchPushNotification;
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
    ): Notification {
        $notification = Notification::query()->create([
            'user_id' => $user instanceof User ? $user->id : $user,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'image_path' => $imagePath,
            'data_json' => $data === [] ? null : $data,
            'channel' => $channel,
            'sent_at' => now(),
            'delivery_status' => $deliveryStatus,
        ]);

        if ($channel === 'push') {
            DispatchPushNotification::dispatch($notification);
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
    ): Collection {
        $notifications = collect();

        foreach ($users as $user) {
            $notifications->push(
                $this->create($user, $type, $title, $body, $data, $channel, $deliveryStatus, $imagePath),
            );
        }

        return $notifications;
    }
}
