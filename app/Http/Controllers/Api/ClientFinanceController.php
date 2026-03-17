<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ClientFundingRequest;
use App\Models\PlatformPaymentSetting;
use App\Models\User;
use App\Support\ClientWalletAvailability;
use App\Services\WalletLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientFinanceController extends Controller
{
    public function __construct(private readonly WalletLedgerService $walletLedgerService)
    {
    }

    public function wallet(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $wallet = $this->walletLedgerService->walletFor($client, 'client_main');
        $setting = PlatformPaymentSetting::query()->first();

        return response()->json([
            'wallets' => [$wallet->fresh()],
            'transactions' => $wallet
                ->transactions()
                ->latest()
                ->take(20)
                ->get(),
            'funding_requests' => $client->clientFundingRequests()->latest()->take(20)->get(),
            'funding_config' => $setting ? [
                'active_method' => $setting->active_method,
                'manual_bank_name' => $setting->manual_bank_name,
                'manual_account_name' => $setting->manual_account_name,
                'manual_account_number' => $setting->manual_account_number,
            ] : null,
            'available_balance' => ClientWalletAvailability::available($client),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $wallet = $this->walletLedgerService->walletFor($client, 'client_main');

        return response()->json([
            'transactions' => $wallet
                ->transactions()
                ->latest()
                ->paginate(30),
        ]);
    }

    public function requestFunding(Request $request): JsonResponse
    {
        $client = $this->client($request);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'payment_method' => ['required', 'string', 'in:manual,automatic'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $fundingRequest = ClientFundingRequest::query()->create([
            'client_id' => $client->id,
            'amount' => $validated['amount'],
            'payment_method' => $validated['payment_method'],
            'note' => $validated['note'] ?? null,
            'status' => 'pending',
            'submitted_at' => now(),
        ]);

        return response()->json([
            'message' => 'Funding request submitted for admin approval.',
            'funding_request' => $fundingRequest,
        ], 201);
    }

    private function client(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->role === 'client', 403);

        return $user;
    }
}
