<?php

namespace App\Services;

use App\Models\ClientFundingRequest;
use App\Models\PlatformPaymentSetting;
use App\Models\User;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentGatewayService
{
    public function __construct(
        protected HttpFactory $http,
        protected WalletLedgerService $walletLedgerService,
        protected NotificationService $notificationService,
    ) {
    }

    public function initialize(User $client, string $method, float $amount): array
    {
        $settings = PlatformPaymentSetting::query()->first();

        if (! $settings || ! in_array($method, $settings->enabledMethods(), true)) {
            throw ValidationException::withMessages([
                'payment_method' => ['This payment method is not available right now.'],
            ]);
        }

        return match ($method) {
            'stripe' => $this->initializeStripe($client, $settings, $amount),
            'paystack' => $this->initializePaystack($client, $settings, $amount),
            'flutterwave' => $this->initializeFlutterwave($client, $settings, $amount),
            default => throw ValidationException::withMessages([
                'payment_method' => ['Only automated gateways can be initialized here.'],
            ]),
        };
    }

    public function completePaystack(ClientFundingRequest $fundingRequest, string $reference): bool
    {
        $settings = PlatformPaymentSetting::query()->first();
        $secret = $settings?->paystack_secret_key;

        if (! $secret) {
            return false;
        }

        $response = $this->http
            ->withToken($secret)
            ->acceptJson()
            ->get("https://api.paystack.co/transaction/verify/{$reference}");

        if (! $response->successful()) {
            return false;
        }

        $payload = $response->json();
        $data = $payload['data'] ?? [];

        if (($data['status'] ?? null) !== 'success') {
            return false;
        }

        return $this->approveAutomaticFunding(
            fundingRequest: $fundingRequest,
            providerReference: (string) ($data['reference'] ?? $reference),
            payload: $payload,
        );
    }

    public function completeFlutterwave(ClientFundingRequest $fundingRequest, string $txRef): bool
    {
        $settings = PlatformPaymentSetting::query()->first();
        $secret = $settings?->flutterwave_secret_key;

        if (! $secret) {
            return false;
        }

        $response = $this->http
            ->withToken($secret)
            ->acceptJson()
            ->get('https://api.flutterwave.com/v3/transactions/verify_by_reference', [
                'tx_ref' => $txRef,
            ]);

        if (! $response->successful()) {
            return false;
        }

        $payload = $response->json();
        $data = $payload['data'] ?? [];

        if (($data['status'] ?? null) !== 'successful') {
            return false;
        }

        return $this->approveAutomaticFunding(
            fundingRequest: $fundingRequest,
            providerReference: (string) ($data['tx_ref'] ?? $txRef),
            payload: $payload,
        );
    }

    public function completeStripe(ClientFundingRequest $fundingRequest, string $sessionId): bool
    {
        $settings = PlatformPaymentSetting::query()->first();
        $secret = $settings?->stripe_secret_key;

        if (! $secret) {
            return false;
        }

        $response = $this->http
            ->withBasicAuth($secret, '')
            ->acceptJson()
            ->get("https://api.stripe.com/v1/checkout/sessions/{$sessionId}");

        if (! $response->successful()) {
            return false;
        }

        $payload = $response->json();

        if (($payload['payment_status'] ?? null) !== 'paid') {
            return false;
        }

        return $this->approveAutomaticFunding(
            fundingRequest: $fundingRequest,
            providerReference: (string) ($payload['id'] ?? $sessionId),
            payload: $payload,
        );
    }

    protected function initializePaystack(User $client, PlatformPaymentSetting $settings, float $amount): array
    {
        if (! $settings->paystack_secret_key) {
            throw ValidationException::withMessages([
                'payment_method' => ['Paystack keys are not configured yet.'],
            ]);
        }

        $fundingRequest = $this->makeFundingRequest($client, $amount, 'paystack');
        $reference = $this->reference('paystack', $fundingRequest->id);

        $response = $this->http
            ->withToken($settings->paystack_secret_key)
            ->acceptJson()
            ->post('https://api.paystack.co/transaction/initialize', [
                'amount' => (string) ((int) round($amount * 100)),
                'email' => $client->email,
                'currency' => 'NGN',
                'reference' => $reference,
                'callback_url' => url("/payments/paystack/callback?funding_request={$fundingRequest->id}"),
            ]);

        if (! $response->successful()) {
            $this->markFailed($fundingRequest, $response->json());

            throw ValidationException::withMessages([
                'payment_method' => ['Unable to initialize Paystack payment right now.'],
            ]);
        }

        $payload = $response->json();
        $data = $payload['data'] ?? [];

        $fundingRequest->update([
            'provider_reference' => $reference,
            'checkout_url' => $data['authorization_url'] ?? null,
            'provider_payload' => $payload,
            'status' => 'processing',
        ]);

        return [
            'message' => 'Paystack payment initialized successfully.',
            'funding_request' => $fundingRequest->fresh(),
            'checkout_url' => $fundingRequest->checkout_url,
        ];
    }

    protected function initializeFlutterwave(User $client, PlatformPaymentSetting $settings, float $amount): array
    {
        if (! $settings->flutterwave_secret_key) {
            throw ValidationException::withMessages([
                'payment_method' => ['Flutterwave keys are not configured yet.'],
            ]);
        }

        $fundingRequest = $this->makeFundingRequest($client, $amount, 'flutterwave');
        $reference = $this->reference('flutterwave', $fundingRequest->id);

        $response = $this->http
            ->withToken($settings->flutterwave_secret_key)
            ->acceptJson()
            ->post('https://api.flutterwave.com/v3/payments', [
                'tx_ref' => $reference,
                'amount' => $amount,
                'currency' => 'NGN',
                'redirect_url' => url("/payments/flutterwave/callback?funding_request={$fundingRequest->id}"),
                'customer' => [
                    'email' => $client->email,
                    'name' => $client->name,
                ],
                'customizations' => [
                    'title' => 'TaskPoint Wallet Funding',
                    'description' => 'Client wallet funding',
                ],
            ]);

        if (! $response->successful()) {
            $this->markFailed($fundingRequest, $response->json());

            throw ValidationException::withMessages([
                'payment_method' => ['Unable to initialize Flutterwave payment right now.'],
            ]);
        }

        $payload = $response->json();
        $data = $payload['data'] ?? [];

        $fundingRequest->update([
            'provider_reference' => $reference,
            'checkout_url' => $data['link'] ?? null,
            'provider_payload' => $payload,
            'status' => 'processing',
        ]);

        return [
            'message' => 'Flutterwave payment initialized successfully.',
            'funding_request' => $fundingRequest->fresh(),
            'checkout_url' => $fundingRequest->checkout_url,
        ];
    }

    protected function initializeStripe(User $client, PlatformPaymentSetting $settings, float $amount): array
    {
        if (! $settings->stripe_secret_key) {
            throw ValidationException::withMessages([
                'payment_method' => ['Stripe keys are not configured yet.'],
            ]);
        }

        $fundingRequest = $this->makeFundingRequest($client, $amount, 'stripe');

        $response = $this->http
            ->withBasicAuth($settings->stripe_secret_key, '')
            ->acceptJson()
            ->asForm()
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => url("/payments/stripe/callback?funding_request={$fundingRequest->id}&session_id={CHECKOUT_SESSION_ID}"),
                'cancel_url' => url("/payments/stripe/cancel?funding_request={$fundingRequest->id}"),
                'customer_email' => $client->email,
                'line_items[0][price_data][currency]' => 'ngn',
                'line_items[0][price_data][product_data][name]' => 'TaskPoint Wallet Funding',
                'line_items[0][price_data][unit_amount]' => (string) ((int) round($amount * 100)),
                'line_items[0][quantity]' => '1',
                'metadata[funding_request_id]' => (string) $fundingRequest->id,
            ]);

        if (! $response->successful()) {
            $this->markFailed($fundingRequest, $response->json());

            throw ValidationException::withMessages([
                'payment_method' => ['Unable to initialize Stripe payment right now.'],
            ]);
        }

        $payload = $response->json();

        $fundingRequest->update([
            'provider_reference' => (string) ($payload['id'] ?? $this->reference('stripe', $fundingRequest->id)),
            'checkout_url' => $payload['url'] ?? null,
            'provider_payload' => $payload,
            'status' => 'processing',
        ]);

        return [
            'message' => 'Stripe payment initialized successfully.',
            'funding_request' => $fundingRequest->fresh(),
            'checkout_url' => $fundingRequest->checkout_url,
        ];
    }

    protected function makeFundingRequest(User $client, float $amount, string $method): ClientFundingRequest
    {
        return ClientFundingRequest::query()->create([
            'client_id' => $client->id,
            'amount' => $amount,
            'payment_method' => $method,
            'status' => 'processing',
            'submitted_at' => now(),
        ]);
    }

    protected function markFailed(ClientFundingRequest $fundingRequest, mixed $payload): void
    {
        $fundingRequest->update([
            'status' => 'failed',
            'provider_payload' => is_array($payload) ? $payload : null,
        ]);
    }

    protected function approveAutomaticFunding(ClientFundingRequest $fundingRequest, string $providerReference, array $payload): bool
    {
        if ($fundingRequest->status === 'approved') {
            return true;
        }

        return DB::transaction(function () use ($fundingRequest, $providerReference, $payload): bool {
            $freshRequest = ClientFundingRequest::query()->lockForUpdate()->findOrFail($fundingRequest->id);

            if ($freshRequest->status === 'approved') {
                return true;
            }

            $freshRequest->update([
                'status' => 'approved',
                'provider_reference' => $providerReference,
                'provider_payload' => $payload,
                'paid_at' => now(),
                'reviewed_at' => now(),
            ]);

            $wallet = $this->walletLedgerService->walletFor($freshRequest->client_id, 'client_main');

            $this->walletLedgerService->credit($wallet, (float) $freshRequest->amount, [
                'transaction_type' => 'client_wallet_funding',
                'reference_type' => ClientFundingRequest::class,
                'reference_id' => $freshRequest->id,
                'status' => 'approved',
                'description' => strtoupper($freshRequest->payment_method).' wallet funding verified automatically.',
            ], 0, 0);

            $this->notificationService->create(
                $freshRequest->client_id,
                'wallet_funding_approved',
                'Wallet funding approved',
                'Your wallet funding was confirmed automatically and your balance has been updated.',
                [
                    'funding_request_id' => $freshRequest->id,
                    'amount' => $freshRequest->amount,
                    'payment_method' => $freshRequest->payment_method,
                ],
            );

            return true;
        });
    }

    protected function reference(string $prefix, int $id): string
    {
        return strtoupper($prefix).'-'.$id.'-'.Str::upper(Str::random(10));
    }
}
