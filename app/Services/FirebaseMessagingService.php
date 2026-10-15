<?php

namespace App\Services;

use App\Models\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FirebaseMessagingService
{
    public function sendNotification(?Notification $notification): void
    {
        if (! $notification) {
            return;
        }

        $tokens = $notification->user?->pushTokens()
            ->where('active', true)
            ->pluck('token')
            ->filter()
            ->values()
            ->all() ?? [];

        if ($tokens === []) {
            $notification->update([
                'delivery_status' => 'no_tokens',
            ]);

            return;
        }

        if (! config('services.firebase.enabled')) {
            Log::info('Firebase notification dispatch skipped because Firebase is disabled.', [
                'notification_id' => $notification->id,
                'user_id' => $notification->user_id,
                'tokens' => count($tokens),
            ]);

            $notification->update([
                'delivery_status' => 'disabled',
            ]);

            return;
        }

        $serviceAccount = $this->serviceAccountCredentials();
        $projectId = config('services.firebase.project_id') ?: ($serviceAccount['project_id'] ?? null);
        $endpointTemplate = config('services.firebase.endpoint');

        if (! is_array($serviceAccount) || ! $projectId || ! is_string($endpointTemplate) || $endpointTemplate === '') {
            $notification->update([
                'delivery_status' => 'misconfigured',
            ]);

            return;
        }

        $accessToken = $this->accessToken($serviceAccount);

        if (! is_string($accessToken) || $accessToken === '') {
            $notification->update([
                'delivery_status' => 'misconfigured',
            ]);

            return;
        }

        $endpoint = str_replace('{project_id}', $projectId, $endpointTemplate);
        $successCount = 0;
        $failureCount = 0;

        foreach ($tokens as $token) {
            $data = $this->normalizeDataPayload(array_filter([
                'notification_id' => $notification->id,
                'type' => $notification->type,
                'image_url' => $notification->image_url,
                ...($notification->data_json ?? []),
            ], static fn ($value) => $value !== null));

            $response = Http::withToken($accessToken)
                ->acceptJson()
                ->post($endpoint, [
                    'message' => [
                        'token' => $token,
                        'notification' => array_filter([
                            'title' => $notification->title,
                            'body' => $notification->body,
                            'image' => $notification->image_url,
                        ]),
                        'data' => $data,
                    ],
                ]);

            if ($response->successful()) {
                $successCount++;

                continue;
            }

            $failureCount++;

            Log::warning('Firebase HTTP v1 notification dispatch failed.', [
                'notification_id' => $notification->id,
                'token' => $token,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }

        $notification->update([
            'delivery_status' => $failureCount > 0 && $successCount > 0
                ? 'partial'
                : ($successCount > 0 ? 'sent' : 'failed'),
            'sent_at' => now(),
        ]);
    }

    /**
     * Deliver one broadcast notification to an FCM topic and update the
     * corresponding in-app notification rows with the same delivery result.
     *
     * @param  array<int, int>  $notificationIds
     */
    public function sendTopicNotification(?Notification $notification, string $topic, array $notificationIds = []): void
    {
        if (! $notification || $topic === '') {
            return;
        }

        $notificationIds = array_values(array_unique(array_map('intval', $notificationIds ?: [$notification->id])));

        if (! config('services.firebase.enabled')) {
            Log::info('Firebase topic notification dispatch skipped because Firebase is disabled.', [
                'notification_id' => $notification->id,
                'topic' => $topic,
            ]);

            $this->updateDeliveryStatus($notificationIds, 'disabled');

            return;
        }

        $serviceAccount = $this->serviceAccountCredentials();
        $projectId = config('services.firebase.project_id') ?: ($serviceAccount['project_id'] ?? null);
        $endpointTemplate = config('services.firebase.endpoint');

        if (! is_array($serviceAccount) || ! $projectId || ! is_string($endpointTemplate) || $endpointTemplate === '') {
            $this->updateDeliveryStatus($notificationIds, 'misconfigured');

            return;
        }

        $accessToken = $this->accessToken($serviceAccount);

        if (! is_string($accessToken) || $accessToken === '') {
            $this->updateDeliveryStatus($notificationIds, 'misconfigured');

            return;
        }

        $data = $this->normalizeDataPayload(array_filter([
            'type' => $notification->type,
            'image_url' => $notification->image_url,
            'topic' => $topic,
            ...($notification->data_json ?? []),
        ], static fn ($value) => $value !== null));

        $endpoint = str_replace('{project_id}', $projectId, $endpointTemplate);
        $response = Http::withToken($accessToken)
            ->acceptJson()
            ->post($endpoint, [
                'message' => [
                    'topic' => $topic,
                    'notification' => array_filter([
                        'title' => $notification->title,
                        'body' => $notification->body,
                        'image' => $notification->image_url,
                    ]),
                    'data' => $data,
                ],
            ]);

        if (! $response->successful()) {
            Log::warning('Firebase topic notification dispatch failed.', [
                'notification_id' => $notification->id,
                'topic' => $topic,
                'status' => $response->status(),
                'body' => $response->json(),
            ]);

            $this->updateDeliveryStatus($notificationIds, 'failed');

            return;
        }

        $this->updateDeliveryStatus($notificationIds, 'sent');
    }

    /**
     * Subscribe a native FCM registration token to the role topics used for
     * platform-wide broadcasts. This uses Firebase's topic-management API.
     * If no server key is configured, direct-token delivery remains available.
     *
     * @param  array<int, string>  $topics
     */
    public function subscribeTokenToTopics(string $token, array $topics): void
    {
        $serverKey = config('services.firebase.server_key');
        $baseEndpoint = config('services.firebase.topic_management_endpoint');

        if (! config('services.firebase.enabled') || ! is_string($serverKey) || $serverKey === '' || ! is_string($baseEndpoint) || $baseEndpoint === '') {
            return;
        }

        foreach (array_values(array_unique(array_filter($topics))) as $topic) {
            $response = Http::withHeaders([
                'Authorization' => 'key='.$serverKey,
            ])
                ->acceptJson()
                ->timeout(10)
                ->post(rtrim($baseEndpoint, '/').'/'.rawurlencode($token).'/rel/topics/'.rawurlencode($topic));

            if (! $response->successful()) {
                Log::warning('Firebase topic subscription failed.', [
                    'topic' => $topic,
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    public function topicsForRole(string $role): array
    {
        $topics = config('services.firebase.topics', []);
        $roleTopic = $topics[$role.'s'] ?? null;
        $allTopic = $topics['all'] ?? null;

        return array_values(array_filter([$roleTopic, $allTopic], static fn ($topic) => is_string($topic) && $topic !== ''));
    }

    public function topicForAudience(string $audience): ?string
    {
        if (! $this->topicManagementConfigured()) {
            return null;
        }

        $topics = config('services.firebase.topics', []);

        $topic = match ($audience) {
            'clients' => $topics['clients'] ?? null,
            'freelancers' => $topics['freelancers'] ?? null,
            'both' => $topics['all'] ?? null,
            default => null,
        };

        return is_string($topic) && $topic !== '' ? $topic : null;
    }

    private function topicManagementConfigured(): bool
    {
        return config('services.firebase.enabled')
            && is_string(config('services.firebase.server_key'))
            && config('services.firebase.server_key') !== ''
            && is_string(config('services.firebase.topic_management_endpoint'))
            && config('services.firebase.topic_management_endpoint') !== '';
    }

    private function serviceAccountCredentials(): ?array
    {
        $path = config('services.firebase.service_account_json');

        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function accessToken(array $serviceAccount): ?string
    {
        $clientEmail = $serviceAccount['client_email'] ?? null;
        $privateKey = $serviceAccount['private_key'] ?? null;
        $tokenUri = config('services.firebase.oauth_token_uri') ?: ($serviceAccount['token_uri'] ?? null);

        if (! is_string($clientEmail) || ! is_string($privateKey) || ! is_string($tokenUri) || $clientEmail === '' || $privateKey === '') {
            return null;
        }

        $cacheKey = 'firebase_access_token_'.md5($clientEmail);

        return Cache::remember($cacheKey, now()->addMinutes(50), function () use ($clientEmail, $privateKey, $tokenUri): ?string {
            $issuedAt = time();
            $expiresAt = $issuedAt + 3600;

            $header = $this->base64UrlEncode(json_encode([
                'alg' => 'RS256',
                'typ' => 'JWT',
            ]));

            $payload = $this->base64UrlEncode(json_encode([
                'iss' => $clientEmail,
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => $tokenUri,
                'iat' => $issuedAt,
                'exp' => $expiresAt,
            ]));

            $signatureInput = $header.'.'.$payload;
            $signature = '';

            if (! openssl_sign($signatureInput, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
                return null;
            }

            $assertion = $signatureInput.'.'.$this->base64UrlEncode($signature);

            $response = Http::asForm()->post($tokenUri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]);

            if ($response->failed()) {
                Log::warning('Firebase OAuth token exchange failed.', [
                    'status' => $response->status(),
                    'body' => $response->json(),
                ]);

                return null;
            }

            return $response->json('access_token');
        });
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * @param  array<int, int>  $notificationIds
     */
    private function updateDeliveryStatus(array $notificationIds, string $status): void
    {
        Notification::query()
            ->whereIn('id', $notificationIds)
            ->update([
                'delivery_status' => $status,
                'sent_at' => now(),
            ]);
    }

    /**
     * FCM HTTP v1 requires every entry in message.data to be a string.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, string>
     */
    private function normalizeDataPayload(array $payload): array
    {
        $normalized = [];

        foreach ($payload as $key => $value) {
            if (is_bool($value)) {
                $normalized[$key] = $value ? 'true' : 'false';

                continue;
            }

            if (is_scalar($value)) {
                $normalized[$key] = (string) $value;

                continue;
            }

            $normalized[$key] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return $normalized;
    }
}
