<?php

use App\Jobs\DispatchPushNotification;
use App\Models\Notification;
use App\Models\PushToken;
use App\Models\User;
use App\Services\FirebaseMessagingService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('queues push delivery when a push notification is created', function () {
    Queue::fake();

    $user = User::factory()->create(['role' => 'freelancer']);

    $notification = app(NotificationService::class)->create(
        $user,
        'task_assigned',
        'New task assigned',
        'A new task is waiting for you.',
        ['assignment_id' => 1],
    );

    expect($notification)->toBeInstanceOf(Notification::class);

    Queue::assertPushed(DispatchPushNotification::class);
});

it('marks a notification as no_tokens when the user has no active device tokens', function () {
    $user = User::factory()->create(['role' => 'freelancer']);

    $notification = Notification::query()->create([
        'user_id' => $user->id,
        'type' => 'submission_approved',
        'title' => 'Approved',
        'body' => 'Your proof was approved.',
        'channel' => 'push',
        'delivery_status' => 'queued',
    ]);

    app(FirebaseMessagingService::class)->sendNotification($notification->fresh('user.pushTokens'));

    expect($notification->fresh()->delivery_status)->toBe('no_tokens');
});

it('sends a firebase request when active tokens exist and firebase is enabled', function () {
    config()->set('services.firebase.enabled', true);
    config()->set('services.firebase.project_id', 'demo-project');
    config()->set('services.firebase.service_account_json', base_path('tests/Fixtures/firebase-service-account.json'));
    config()->set('services.firebase.oauth_token_uri', 'https://oauth2.example.test/token');
    config()->set('services.firebase.endpoint', 'https://fcm.googleapis.com/v1/projects/{project_id}/messages:send');

    Http::fake([
        'https://oauth2.example.test/token' => Http::response(['access_token' => 'demo-access-token'], 200),
        'https://fcm.googleapis.com/v1/projects/demo-project/messages:send' => Http::response(['name' => 'projects/demo/messages/1'], 200),
    ]);

    $user = User::factory()->create(['role' => 'freelancer']);

    PushToken::query()->create([
        'user_id' => $user->id,
        'token' => 'token-123',
        'provider' => 'firebase',
        'active' => true,
    ]);

    $notification = Notification::query()->create([
        'user_id' => $user->id,
        'type' => 'task_assigned',
        'title' => 'Task ready',
        'body' => 'You have a new assignment.',
        'channel' => 'push',
        'delivery_status' => 'queued',
    ]);

    app(FirebaseMessagingService::class)->sendNotification($notification->fresh('user.pushTokens'));

    Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.example.test/token'
        && $request['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer');

    Http::assertSent(fn ($request) => $request->url() === 'https://fcm.googleapis.com/v1/projects/demo-project/messages:send'
        && $request->hasHeader('Authorization', 'Bearer demo-access-token')
        && $request['message']['notification']['title'] === 'Task ready'
        && $request['message']['token'] === 'token-123');

    expect($notification->fresh()->delivery_status)->toBe('sent');
});

it('normalizes firebase data payload values to strings before dispatch', function () {
    config()->set('services.firebase.enabled', true);
    config()->set('services.firebase.project_id', 'demo-project');
    config()->set('services.firebase.service_account_json', base_path('tests/Fixtures/firebase-service-account.json'));
    config()->set('services.firebase.oauth_token_uri', 'https://oauth2.example.test/token');
    config()->set('services.firebase.endpoint', 'https://fcm.googleapis.com/v1/projects/{project_id}/messages:send');

    Http::fake([
        'https://oauth2.example.test/token' => Http::response(['access_token' => 'demo-access-token'], 200),
        'https://fcm.googleapis.com/v1/projects/demo-project/messages:send' => Http::response(['name' => 'projects/demo/messages/2'], 200),
    ]);

    $user = User::factory()->create(['role' => 'freelancer']);

    PushToken::query()->create([
        'user_id' => $user->id,
        'token' => 'token-456',
        'provider' => 'firebase',
        'active' => true,
    ]);

    $notification = Notification::query()->create([
        'user_id' => $user->id,
        'type' => 'admin_broadcast',
        'title' => 'Broadcast',
        'body' => 'Payload test.',
        'data_json' => [
            'sent_by_admin_id' => 1,
            'urgent' => true,
            'meta' => ['source' => 'admin'],
        ],
        'channel' => 'push',
        'delivery_status' => 'queued',
    ]);

    app(FirebaseMessagingService::class)->sendNotification($notification->fresh('user.pushTokens'));

    Http::assertSent(function ($request) {
        if ($request->url() !== 'https://fcm.googleapis.com/v1/projects/demo-project/messages:send') {
            return false;
        }

        return $request['message']['data']['notification_id'] === (string) $request['message']['data']['notification_id']
            && $request['message']['data']['sent_by_admin_id'] === '1'
            && $request['message']['data']['urgent'] === 'true'
            && $request['message']['data']['meta'] === '{"source":"admin"}';
    });

    expect($notification->fresh()->delivery_status)->toBe('sent');
});
