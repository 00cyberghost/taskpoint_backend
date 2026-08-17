<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class GoogleIdentityService
{
    public function verify(string $idToken): array
    {
        $allowedAudiences = array_values(array_filter(config('services.google.client_ids', [])));

        if ($allowedAudiences === []) {
            throw ValidationException::withMessages([
                'google' => ['Google sign-in is not configured on the server yet.'],
            ]);
        }

        $client = new GoogleClient([
            'client_id' => Arr::first($allowedAudiences),
        ]);

        $payload = $client->verifyIdToken($idToken);

        if (! is_array($payload)) {
            throw ValidationException::withMessages([
                'google' => ['Unable to verify your Google account right now.'],
            ]);
        }

        if (! in_array($payload['aud'] ?? null, $allowedAudiences, true)) {
            throw ValidationException::withMessages([
                'google' => ['This Google credential was issued for a different application.'],
            ]);
        }

        if (! in_array($payload['iss'] ?? null, ['accounts.google.com', 'https://accounts.google.com'], true)) {
            throw ValidationException::withMessages([
                'google' => ['This Google credential was issued by an unexpected issuer.'],
            ]);
        }

        if (empty($payload['email']) || empty($payload['sub'])) {
            throw ValidationException::withMessages([
                'google' => ['Google did not return the account details we need.'],
            ]);
        }

        if (! (bool) ($payload['email_verified'] ?? false)) {
            throw ValidationException::withMessages([
                'google' => ['Your Google email address must be verified before you can continue.'],
            ]);
        }

        return $payload;
    }
}
