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
                        'data' => array_filter([
                            'notification_id' => (string) $notification->id,
                            'type' => $notification->type,
                            'image_url' => $notification->image_url,
                            ...($notification->data_json ?? []),
                        ], static fn ($value) => $value !== null),
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
}
