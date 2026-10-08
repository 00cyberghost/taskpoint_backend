<?php

namespace App\Http\Controllers;

use App\Models\ClientFundingRequest;
use App\Models\PlatformPaymentSetting;
use App\Models\WithdrawalRequest;
use App\Services\PaymentGatewayService;
use App\Services\PayoutGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MonnifyWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayService $paymentGatewayService,
        private readonly PayoutGatewayService $payoutGatewayService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $settings = PlatformPaymentSetting::query()->first();
        $secret = (string) ($settings?->monnify_secret_key ?? '');
        $signature = (string) $request->header('monnify-signature');

        if ($secret === '') {
            return response()->json(['message' => 'Monnify is not configured.'], 503);
        }

        if ($signature !== '') {
            $expectedSignature = hash_hmac('sha512', $request->getContent(), $secret);

            if (! hash_equals($expectedSignature, $signature)) {
                Log::warning('Monnify webhook signature validation failed.');

                return response()->json(['message' => 'Invalid signature.'], 401);
            }
        } elseif ($settings->monnify_environment === 'live') {
            Log::warning('Monnify live webhook did not include a signature.');

            return response()->json(['message' => 'Missing signature.'], 401);
        }

        $reference = (string) ($request->input('eventData.paymentReference')
            ?? $request->input('eventData.reference')
            ?? $request->input('data.reference'));
        $withdrawal = WithdrawalRequest::query()
            ->where('payout_method', 'monnify')
            ->where('provider_reference', $reference)
            ->first();

        if ($withdrawal) {
            $status = strtoupper((string) ($request->input('eventData.status')
                ?? $request->input('data.status')));

            if (in_array($status, ['SUCCESS', 'COMPLETED'], true)) {
                $this->payoutGatewayService->settle($withdrawal, null, $request->json()->all());
            } elseif (in_array($status, ['FAILED', 'REVERSED', 'EXPIRED'], true)) {
                $this->payoutGatewayService->fail(
                    $withdrawal,
                    (string) ($request->input('eventData.responseMessage') ?? 'Monnify could not complete this payout.'),
                    $request->json()->all(),
                );
            }

            return response()->json(['received' => true]);
        }

        if ((string) $request->input('eventType') !== 'SUCCESSFUL_TRANSACTION') {
            return response()->json(['received' => true]);
        }

        $fundingRequest = ClientFundingRequest::query()
            ->where('payment_method', 'monnify')
            ->where('provider_reference', $reference)
            ->first();

        if (! $fundingRequest) {
            Log::warning('Monnify webhook received for an unknown funding request.', [
                'reference' => $reference,
            ]);

            return response()->json(['received' => true]);
        }

        $completed = $this->paymentGatewayService->completeMonnify($fundingRequest, $reference);

        return response()->json([
            'received' => true,
            'processed' => $completed,
        ]);
    }
}
