<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Notification;
use App\Models\PushToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'notifications' => $this->client($request)
                ->notifications()
                ->latest()
                ->paginate(20),
        ]);
    }

    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        $client = $this->client($request);
        abort_unless($notification->user_id === $client->id, 403);

        $notification->update([
            'read_at' => now(),
        ]);

        return response()->json([
            'message' => 'Notification marked as read.',
        ]);
    }

    public function registerPushToken(Request $request): JsonResponse
    {
        $client = $this->client($request);

        $validated = $request->validate([
            'device_identifier' => ['required', 'string', 'max:255'],
            'platform' => ['nullable', 'string', 'max:50'],
            'model' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:50'],
            'token' => ['required', 'string'],
            'provider' => ['nullable', 'string', 'max:50'],
        ]);

        $device = Device::query()->updateOrCreate(
            ['device_identifier' => $validated['device_identifier']],
            [
                'user_id' => $client->id,
                'platform' => $validated['platform'] ?? null,
                'model' => $validated['model'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'last_seen_at' => now(),
            ]
        );

        if ($device->user_id !== $client->id) {
            $device->update(['user_id' => $client->id]);
        }

        $pushToken = PushToken::query()->updateOrCreate(
            [
                'user_id' => $client->id,
                'token' => $validated['token'],
            ],
            [
                'device_id' => $device->id,
                'provider' => $validated['provider'] ?? 'firebase',
                'active' => true,
            ]
        );

        return response()->json([
            'message' => 'Push token registered.',
            'push_token' => $pushToken,
        ]);
    }

    private function client(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->role === 'client', 403);

        return $user;
    }
}
