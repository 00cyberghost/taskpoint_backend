<?php

namespace App\Http\Controllers;

use App\Models\ClientFundingRequest;
use App\Services\PaymentGatewayService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentCallbackController extends Controller
{
    public function __construct(
        protected PaymentGatewayService $paymentGatewayService,
    ) {
    }

    public function paystack(Request $request): Response
    {
        $fundingRequest = ClientFundingRequest::query()->findOrFail((int) $request->query('funding_request'));
        $reference = (string) $request->query('reference', $fundingRequest->provider_reference);

        $success = $reference !== '' && $this->paymentGatewayService->completePaystack($fundingRequest, $reference);

        return response($this->htmlResponse(
            $success,
            'Paystack',
            $success ? 'Payment confirmed. Your wallet has been updated.' : 'Payment could not be confirmed yet.',
        ));
    }

    public function flutterwave(Request $request): Response
    {
        $fundingRequest = ClientFundingRequest::query()->findOrFail((int) $request->query('funding_request'));
        $reference = (string) $request->query('tx_ref', $fundingRequest->provider_reference);

        $success = $reference !== '' && $this->paymentGatewayService->completeFlutterwave($fundingRequest, $reference);

        return response($this->htmlResponse(
            $success,
            'Flutterwave',
            $success ? 'Payment confirmed. Your wallet has been updated.' : 'Payment could not be confirmed yet.',
        ));
    }

    public function stripe(Request $request): Response
    {
        $fundingRequest = ClientFundingRequest::query()->findOrFail((int) $request->query('funding_request'));
        $sessionId = (string) $request->query('session_id', $fundingRequest->provider_reference);

        $success = $sessionId !== '' && $this->paymentGatewayService->completeStripe($fundingRequest, $sessionId);

        return response($this->htmlResponse(
            $success,
            'Stripe',
            $success ? 'Payment confirmed. Your wallet has been updated.' : 'Payment could not be confirmed yet.',
        ));
    }

    public function cancel(Request $request, string $provider): Response
    {
        return response($this->htmlResponse(
            false,
            ucfirst($provider),
            'Payment was cancelled. You can return to the app and try again.',
        ));
    }

    protected function htmlResponse(bool $success, string $provider, string $message): string
    {
        $color = $success ? '#16a34a' : '#b45309';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$provider} Payment Status</title>
</head>
<body style="margin:0;background:#f7f2ef;font-family:Arial,Helvetica,sans-serif;color:#23110d;">
    <div style="max-width:560px;margin:0 auto;padding:48px 20px;">
        <div style="background:#ffffff;border-radius:24px;padding:32px;border:1px solid #eadfd7;text-align:center;">
            <h1 style="margin:0 0 16px;font-size:28px;color:{$color};">{$provider} Payment</h1>
            <p style="margin:0;font-size:16px;line-height:1.7;">{$message}</p>
            <p style="margin:24px 0 0;font-size:13px;line-height:1.7;color:#9b8a83;">
                You can now return to the TaskPoint app.
            </p>
        </div>
    </div>
</body>
</html>
HTML;
    }
}
