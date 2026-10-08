<?php

namespace App\Services;

use App\Models\PlatformPaymentSetting;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PayoutGatewayService
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly WalletLedgerService $walletLedgerService,
        private readonly NotificationService $notificationService,
    ) {}

    public function process(WithdrawalRequest $withdrawal, ?int $adminId = null): string
    {
        if ($withdrawal->status === 'paid') {
            return 'This withdrawal has already been paid.';
        }

        $settings = PlatformPaymentSetting::query()->first();
        $method = $settings?->default_payout_method ?: 'manual';

        if ($method === 'manual') {
            $this->settle($withdrawal, $adminId);

            return 'Withdrawal paid manually and wallet balance updated.';
        }

        if (! in_array($method, ['paystack', 'flutterwave', 'monnify'], true)) {
            throw ValidationException::withMessages([
                'status' => ['Select a supported payout gateway in finance settings first.'],
            ]);
        }

        if ($withdrawal->status === 'processing' && filled($withdrawal->provider_reference)) {
            return 'This payout has already been sent and is awaiting gateway confirmation.';
        }

        $details = $this->destinationDetails($withdrawal);
        $this->validateDestination($details);
        $this->validateConfiguration($settings, $method);

        $reference = 'taskpoint-payout-'.$withdrawal->id.'-'.Str::lower(Str::random(8));
        $this->reserve($withdrawal, $method, $reference, $adminId);

        try {
            $result = match ($method) {
                'paystack' => $this->sendPaystack($settings, $withdrawal, $details, $reference),
                'flutterwave' => $this->sendFlutterwave($settings, $withdrawal, $details, $reference),
                'monnify' => $this->sendMonnify($settings, $withdrawal, $details, $reference),
            };
        } catch (RequestException $exception) {
            Log::warning('Payout gateway request did not return a conclusive response.', [
                'withdrawal_id' => $withdrawal->id,
                'method' => $method,
                'reference' => $reference,
                'message' => $exception->getMessage(),
            ]);

            return 'Payout request sent. It is awaiting gateway confirmation.';
        }

        $payload = $result['payload'];

        if ($result['state'] === 'success') {
            $this->settle($withdrawal, $adminId, $payload);

            return 'Payout completed and the freelancer wallet was settled.';
        }

        if ($result['state'] === 'pending') {
            $withdrawal->update([
                'status' => 'processing',
                'provider_payload' => $payload,
            ]);

            return 'Payout sent and is awaiting gateway confirmation.';
        }

        $this->fail($withdrawal, $result['message'], $payload);

        throw ValidationException::withMessages([
            'status' => [$result['message']],
        ]);
    }

    public function settle(WithdrawalRequest $withdrawal, ?int $adminId = null, ?array $payload = null): void
    {
        DB::transaction(function () use ($withdrawal, $adminId, $payload): void {
            $fresh = WithdrawalRequest::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if ($fresh->status === 'paid') {
                return;
            }

            $wallet = Wallet::query()->whereKey(
                $this->walletLedgerService->walletFor($fresh->freelancer_id, 'freelancer_main')->id,
            )->lockForUpdate()->firstOrFail();

            $reserved = WalletTransaction::query()
                ->where('reference_type', WithdrawalRequest::class)
                ->where('reference_id', $fresh->id)
                ->where('transaction_type', 'withdrawal_reserve')
                ->where('status', 'processing')
                ->exists();

            if (! $reserved) {
                if ((float) $wallet->withdrawable_balance < (float) $fresh->amount) {
                    throw ValidationException::withMessages([
                        'status' => ['The freelancer does not have enough withdrawable balance to settle this payout.'],
                    ]);
                }

                $this->walletLedgerService->debit($wallet, (float) $fresh->amount, [
                    'transaction_type' => 'withdrawal_payout',
                    'reference_type' => WithdrawalRequest::class,
                    'reference_id' => $fresh->id,
                    'status' => 'paid',
                    'description' => 'Withdrawal settled by payout operations.',
                ], 0, -(float) $fresh->amount);
            } else {
                $this->walletLedgerService->recordMeta($wallet, (float) $fresh->amount, [
                    'transaction_type' => 'withdrawal_payout',
                    'reference_type' => WithdrawalRequest::class,
                    'reference_id' => $fresh->id,
                    'status' => 'paid',
                    'description' => 'Gateway payout confirmed and reserved wallet funds settled.',
                ]);
            }

            $fresh->update([
                'status' => 'paid',
                'payout_method' => $fresh->payout_method ?: 'manual',
                'processed_by' => $adminId ?? $fresh->processed_by,
                'processed_at' => now(),
                'provider_payload' => $payload ?? $fresh->provider_payload,
                'failure_reason' => null,
            ]);

            $this->notificationService->create(
                $fresh->freelancer_id,
                'withdrawal_paid',
                'Withdrawal paid',
                'Your withdrawal has been paid to your saved bank account.',
                [
                    'withdrawal_id' => $fresh->id,
                    'amount' => $fresh->amount,
                    'payment_method' => $fresh->payout_method,
                ],
            );
        });
    }

    public function fail(WithdrawalRequest $withdrawal, string $reason, ?array $payload = null): void
    {
        DB::transaction(function () use ($withdrawal, $reason, $payload): void {
            $fresh = WithdrawalRequest::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if ($fresh->status === 'paid') {
                return;
            }

            $wallet = Wallet::query()->whereKey(
                $this->walletLedgerService->walletFor($fresh->freelancer_id, 'freelancer_main')->id,
            )->lockForUpdate()->firstOrFail();

            $reserved = WalletTransaction::query()
                ->where('reference_type', WithdrawalRequest::class)
                ->where('reference_id', $fresh->id)
                ->where('transaction_type', 'withdrawal_reserve')
                ->where('status', 'processing')
                ->exists();

            $released = WalletTransaction::query()
                ->where('reference_type', WithdrawalRequest::class)
                ->where('reference_id', $fresh->id)
                ->where('transaction_type', 'withdrawal_release')
                ->exists();

            if ($reserved && ! $released) {
                $this->walletLedgerService->credit($wallet, (float) $fresh->amount, [
                    'transaction_type' => 'withdrawal_release',
                    'reference_type' => WithdrawalRequest::class,
                    'reference_id' => $fresh->id,
                    'status' => 'rejected',
                    'description' => 'Reserved payout funds released after gateway failure.',
                ], 0, (float) $fresh->amount);
            }

            $fresh->update([
                'status' => 'rejected',
                'processed_at' => now(),
                'provider_reference' => null,
                'provider_payload' => $payload ?? $fresh->provider_payload,
                'failure_reason' => $reason,
            ]);

            $this->notificationService->create(
                $fresh->freelancer_id,
                'withdrawal_failed',
                'Withdrawal failed',
                'Your withdrawal could not be completed. Please review your bank details or contact support.',
                [
                    'withdrawal_id' => $fresh->id,
                    'amount' => $fresh->amount,
                    'reason' => $reason,
                ],
            );
        });
    }

    /** @return array<string, mixed> */
    private function destinationDetails(WithdrawalRequest $withdrawal): array
    {
        return is_array($withdrawal->destination_details) ? $withdrawal->destination_details : [];
    }

    /** @param array<string, mixed> $details */
    private function validateDestination(array $details): void
    {
        foreach (['account_number', 'account_name', 'bank_name', 'bank_code'] as $field) {
            if (! filled($details[$field] ?? null)) {
                throw ValidationException::withMessages([
                    'status' => ["The freelancer's {$field} is required for automated payouts."],
                ]);
            }
        }
    }

    private function validateConfiguration(?PlatformPaymentSetting $settings, string $method): void
    {
        if (! $settings) {
            throw ValidationException::withMessages([
                'status' => ['Configure a payout gateway in finance settings first.'],
            ]);
        }

        if (! in_array($method, $settings->enabledMethods(), true)) {
            throw ValidationException::withMessages([
                'status' => ["{$method} is disabled in finance settings."],
            ]);
        }

        $configured = match ($method) {
            'paystack' => filled($settings->paystack_secret_key),
            'flutterwave' => filled($settings->flutterwave_secret_key),
            'monnify' => filled($settings->monnify_api_key)
                && filled($settings->monnify_secret_key)
                && filled($settings->monnify_disbursement_account_number),
            default => false,
        };

        if (! $configured) {
            throw ValidationException::withMessages([
                'status' => ["{$method} payout credentials are incomplete in finance settings."],
            ]);
        }
    }

    private function reserve(WithdrawalRequest $withdrawal, string $method, string $reference, ?int $adminId): void
    {
        DB::transaction(function () use ($withdrawal, $method, $reference, $adminId): void {
            $fresh = WithdrawalRequest::query()->lockForUpdate()->findOrFail($withdrawal->id);

            if ($fresh->status === 'paid' || ($fresh->status === 'processing' && filled($fresh->provider_reference))) {
                return;
            }

            $wallet = Wallet::query()->whereKey(
                $this->walletLedgerService->walletFor($fresh->freelancer_id, 'freelancer_main')->id,
            )->lockForUpdate()->firstOrFail();

            if ((float) $wallet->withdrawable_balance < (float) $fresh->amount) {
                throw ValidationException::withMessages([
                    'status' => ['The freelancer does not have enough withdrawable balance to settle this payout.'],
                ]);
            }

            $this->walletLedgerService->debit($wallet, (float) $fresh->amount, [
                'transaction_type' => 'withdrawal_reserve',
                'reference_type' => WithdrawalRequest::class,
                'reference_id' => $fresh->id,
                'status' => 'processing',
                'description' => 'Funds reserved while the payout gateway processes this withdrawal.',
            ], 0, -(float) $fresh->amount);

            $fresh->update([
                'status' => 'processing',
                'payout_method' => $method,
                'provider_reference' => $reference,
                'processed_by' => $adminId ?? $fresh->processed_by,
                'processed_at' => now(),
            ]);
        });
    }

    /** @param array<string, mixed> $details */
    private function sendPaystack(PlatformPaymentSetting $settings, WithdrawalRequest $withdrawal, array $details, string $reference): array
    {
        $recipient = $this->http
            ->withToken((string) $settings->paystack_secret_key)
            ->acceptJson()
            ->post('https://api.paystack.co/transferrecipient', [
                'type' => 'nuban',
                'name' => $details['account_name'],
                'account_number' => $details['account_number'],
                'bank_code' => $details['bank_code'],
                'currency' => 'NGN',
            ]);

        $recipientPayload = $recipient->json() ?? [];

        if (! $recipient->successful() || ($recipientPayload['status'] ?? false) !== true) {
            return $this->failedProviderResult($recipientPayload, 'Paystack could not create the bank recipient.');
        }

        $response = $this->http
            ->withToken((string) $settings->paystack_secret_key)
            ->acceptJson()
            ->post('https://api.paystack.co/transfer', [
                'source' => 'balance',
                'amount' => (int) round((float) $withdrawal->amount * 100),
                'recipient' => $recipientPayload['data']['recipient_code'],
                'reference' => $reference,
                'reason' => 'TaskPoint freelancer payout',
                'currency' => 'NGN',
            ]);

        $payload = $response->json() ?? [];

        if (! $response->successful() || ($payload['status'] ?? false) !== true) {
            return $this->failedProviderResult($payload, 'Paystack rejected this payout.');
        }

        return [
            'state' => ($payload['data']['status'] ?? null) === 'success' ? 'success' : 'pending',
            'payload' => $payload,
            'message' => 'Paystack is still processing this payout.',
        ];
    }

    /** @param array<string, mixed> $details */
    private function sendFlutterwave(PlatformPaymentSetting $settings, WithdrawalRequest $withdrawal, array $details, string $reference): array
    {
        $response = $this->http
            ->withToken((string) $settings->flutterwave_secret_key)
            ->acceptJson()
            ->post('https://api.flutterwave.com/v3/transfers', [
                'account_bank' => $details['bank_code'],
                'account_number' => $details['account_number'],
                'amount' => (float) $withdrawal->amount,
                'currency' => 'NGN',
                'beneficiary_name' => $details['account_name'],
                'reference' => $reference,
                'debit_currency' => 'NGN',
                'narration' => 'TaskPoint freelancer payout',
                'callback_url' => url('/api/webhooks/flutterwave'),
            ]);

        $payload = $response->json() ?? [];
        $status = strtoupper((string) ($payload['data']['status'] ?? ''));

        if (! $response->successful() || ($payload['status'] ?? null) !== 'success') {
            return $this->failedProviderResult($payload, 'Flutterwave rejected this payout.');
        }

        return [
            'state' => $status === 'SUCCESSFUL' ? 'success' : ($status === 'FAILED' ? 'failed' : 'pending'),
            'payload' => $payload,
            'message' => (string) ($payload['data']['complete_message'] ?? 'Flutterwave is still processing this payout.'),
        ];
    }

    /** @param array<string, mixed> $details */
    private function sendMonnify(PlatformPaymentSetting $settings, WithdrawalRequest $withdrawal, array $details, string $reference): array
    {
        $token = $this->monnifyAccessToken($settings);

        if (! $token) {
            return $this->failedProviderResult([], 'Monnify authentication failed.');
        }

        $response = $this->http
            ->withToken($token)
            ->acceptJson()
            ->post($this->monnifyBaseUrl($settings).'/api/v2/disbursements/single', [
                'amount' => (float) $withdrawal->amount,
                'reference' => $reference,
                'narration' => 'TaskPoint freelancer payout',
                'destinationBankCode' => $details['bank_code'],
                'destinationAccountNumber' => $details['account_number'],
                'destinationAccountName' => $details['account_name'],
                'currency' => 'NGN',
                'sourceAccountNumber' => $settings->monnify_disbursement_account_number,
                'async' => true,
            ]);

        $payload = $response->json() ?? [];
        $status = strtoupper((string) ($payload['responseBody']['status'] ?? ''));

        if (! $response->successful() || ($payload['requestSuccessful'] ?? false) !== true) {
            return $this->failedProviderResult($payload, 'Monnify rejected this payout.');
        }

        return [
            'state' => in_array($status, ['SUCCESS', 'COMPLETED'], true) ? 'success' : 'pending',
            'payload' => $payload,
            'message' => 'Monnify is still processing this payout.',
        ];
    }

    /** @return array{state: string, payload: array<string, mixed>, message: string} */
    private function failedProviderResult(array $payload, string $fallback): array
    {
        return [
            'state' => 'failed',
            'payload' => $payload,
            'message' => (string) ($payload['message'] ?? $payload['responseMessage'] ?? $fallback),
        ];
    }

    private function monnifyAccessToken(PlatformPaymentSetting $settings): ?string
    {
        $response = $this->http
            ->withBasicAuth((string) $settings->monnify_api_key, (string) $settings->monnify_secret_key)
            ->acceptJson()
            ->post($this->monnifyBaseUrl($settings).'/api/v1/auth/login');

        return $response->successful() ? $response->json('responseBody.accessToken') : null;
    }

    private function monnifyBaseUrl(PlatformPaymentSetting $settings): string
    {
        return $settings->monnify_environment === 'live'
            ? 'https://api.monnify.com'
            : 'https://sandbox.monnify.com';
    }
}
