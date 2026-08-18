<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Notification;
use App\Models\PushToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FreelancerNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'notifications' => $request->user()
                ->notifications()
                ->latest()
                ->paginate(20),
        ]);
    }

    public function markAsRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 403);

        $notification->update([
            'read_at' => now(),
        ]);

        return response()->json([
            'message' => 'Notification marked as read.',
        ]);
    }

    public function registerPushToken(Request $request): JsonResponse
    {
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
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'] ?? null,
                'model' => $validated['model'] ?? null,
                'app_version' => $validated['app_version'] ?? null,
                'last_seen_at' => now(),
            ]
        );

        if ($device->user_id !== $request->user()->id) {
            $device->update([
                'user_id' => $request->user()->id,
            ]);
        }

        $pushToken = PushToken::query()->updateOrCreate(
            [
                'user_id' => $request->user()->id,
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
}
