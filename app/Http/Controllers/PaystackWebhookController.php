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

class PaystackWebhookController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayService $paymentGatewayService,
        private readonly PayoutGatewayService $payoutGatewayService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $secret = (string) PlatformPaymentSetting::query()->value('paystack_secret_key');
        $signature = (string) $request->header('x-paystack-signature');
        $expectedSignature = hash_hmac('sha512', $request->getContent(), $secret);

        if ($secret === '' || $signature === '' || ! hash_equals($expectedSignature, $signature)) {
            Log::warning('Paystack webhook signature validation failed.');

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        $event = (string) $request->input('event');

        if (in_array($event, ['transfer.success', 'transfer.failed', 'transfer.reversed'], true)) {
            $reference = (string) $request->input('data.reference');
            $withdrawal = WithdrawalRequest::query()
                ->where('payout_method', 'paystack')
                ->where('provider_reference', $reference)
                ->first();

            if ($withdrawal) {
                $amountMatches = (int) $request->input('data.amount') === (int) round((float) $withdrawal->amount * 100);
                $currencyMatches = strtoupper((string) $request->input('data.currency')) === 'NGN';

                if ($amountMatches && $currencyMatches && $event === 'transfer.success') {
                    $this->payoutGatewayService->settle($withdrawal, null, $request->json()->all());
                } elseif ($event !== 'transfer.success') {
                    $this->payoutGatewayService->fail(
                        $withdrawal,
                        (string) ($request->input('data.reason') ?? 'Paystack could not complete this payout.'),
                        $request->json()->all(),
                    );
                }
            }

            return response()->json(['received' => true]);
        }

        if ($event !== 'charge.success') {
            return response()->json(['received' => true]);
        }

        $reference = (string) $request->input('data.reference');
        $fundingRequest = ClientFundingRequest::query()
            ->where('payment_method', 'paystack')
            ->where('provider_reference', $reference)
            ->first();

        if (! $fundingRequest) {
            Log::warning('Paystack webhook received for an unknown funding request.', [
                'reference' => $reference,
            ]);

            return response()->json(['received' => true]);
        }

        $completed = $this->paymentGatewayService->completePaystack($fundingRequest, $reference);

        if (! $completed) {
            Log::warning('Paystack webhook verification did not approve funding request.', [
                'funding_request_id' => $fundingRequest->id,
                'reference' => $reference,
            ]);
        }

        return response()->json([
            'received' => true,
            'processed' => $completed,
        ]);
    }
}
