<?php

namespace App\Http\Controllers;

use App\Models\PlatformPaymentSetting;
use App\Models\WithdrawalRequest;
use App\Services\PayoutGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FlutterwaveWebhookController extends Controller
{
    public function __construct(
        private readonly PayoutGatewayService $payoutGatewayService,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        $secret = (string) PlatformPaymentSetting::query()->value('flutterwave_webhook_secret');
        $signature = (string) $request->header('verif-hash');

        if ($secret === '' || $signature === '' || ! hash_equals($secret, $signature)) {
            Log::warning('Flutterwave webhook signature validation failed.');

            return response()->json(['message' => 'Invalid signature.'], 401);
        }

        if ((string) $request->input('event') !== 'transfer.completed') {
            return response()->json(['received' => true]);
        }

        $reference = (string) $request->input('data.reference');
        $withdrawal = WithdrawalRequest::query()
            ->where('payout_method', 'flutterwave')
            ->where('provider_reference', $reference)
            ->first();

        if (! $withdrawal) {
            return response()->json(['received' => true]);
        }

        $amountMatches = abs((float) $request->input('data.amount') - (float) $withdrawal->amount) < 0.01;
        $currencyMatches = strtoupper((string) $request->input('data.currency')) === 'NGN';
        $status = strtoupper((string) $request->input('data.status'));

        if ($amountMatches && $currencyMatches && $status === 'SUCCESSFUL') {
            $this->payoutGatewayService->settle($withdrawal, null, $request->json()->all());
        } elseif ($status === 'FAILED') {
            $this->payoutGatewayService->fail(
                $withdrawal,
                (string) ($request->input('data.complete_message') ?? 'Flutterwave could not complete this payout.'),
                $request->json()->all(),
            );
        }

        return response()->json(['received' => true]);
    }
}
