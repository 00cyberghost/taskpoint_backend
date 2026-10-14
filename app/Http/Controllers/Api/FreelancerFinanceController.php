<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FreelancerBankChangeRequest;
use App\Models\FreelancerProfile;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use App\Services\NotificationService;
use App\Services\WalletLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FreelancerFinanceController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly WalletLedgerService $walletLedgerService,
    ) {}

    public function wallet(Request $request): JsonResponse
    {
        $user = $request->user();
        $mainWallet = $this->walletLedgerService->walletFor($user, 'freelancer_main');
        $otherWallets = $user->wallets()
            ->where('id', '!=', $mainWallet->id)
            ->get();

        return response()->json([
            'wallets' => collect([$mainWallet->fresh()])->concat($otherWallets)->values(),
            'transactions' => WalletTransaction::query()
                ->where('user_id', $user->id)
                ->latest()
                ->take(20)
                ->get(),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        return response()->json([
            'assignments' => $request->user()->assignments()
                ->with(['campaign', 'submission.proofs'])
                ->latest()
                ->paginate(20),
        ]);
    }

    public function requestWithdrawal(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1000'],
            'destination_type' => ['required', 'string', 'max:50'],
            'destination_details' => ['required', 'array'],
        ]);

        $wallet = $request->user()
            ->wallets()
            ->where('wallet_type', 'freelancer_main')
            ->where('currency', 'NGN')
            ->first();

        $withdrawable = (float) ($wallet?->withdrawable_balance ?? 0);

        if ($withdrawable < (float) $validated['amount']) {
            throw ValidationException::withMessages([
                'amount' => ['Your withdrawable balance is not enough for this request.'],
            ]);
        }

        $profile = FreelancerProfile::query()
            ->where('user_id', $request->user()->id)
            ->first();

        if (! $profile?->bank_name || ! $profile->account_name || ! $profile->account_number) {
            throw ValidationException::withMessages([
                'banking' => ['Add and save your complete bank details before requesting a payout.'],
            ]);
        }

        // Use the approved profile snapshot, not client-provided destination data.
        $destinationDetails = [
            'bank_name' => $profile->bank_name,
            'bank_code' => $profile->bank_code,
            'account_name' => $profile->account_name,
            'account_number' => $profile->account_number,
        ];

        $withdrawal = WithdrawalRequest::query()->create([
            'freelancer_id' => $request->user()->id,
            'amount' => $validated['amount'],
            'destination_type' => $validated['destination_type'],
            'destination_details' => $destinationDetails,
            'status' => 'requested',
            'requested_at' => now(),
        ]);

        $this->notificationService->create(
            $request->user()->id,
            'withdrawal_requested',
            'Withdrawal request submitted',
            'Your payout request has been sent for admin review.',
            [
                'withdrawal_id' => $withdrawal->id,
                'amount' => $withdrawal->amount,
            ],
        );

        return response()->json([
            'message' => 'Withdrawal request created.',
            'withdrawal' => $withdrawal,
        ], 201);
    }

    public function updateBanking(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bank_name' => ['required', 'string', 'max:255'],
            'bank_code' => ['nullable', 'string', 'max:20'],
            'account_name' => ['required', 'string', 'max:255'],
            'account_number' => ['required', 'string', 'max:50'],
        ]);

        $profile = $request->user()->freelancerProfile()->firstOrCreate(
            ['user_id' => $request->user()->id],
            ['phone' => $request->user()->phone],
        );

        $hasActiveBankDetails = filled($profile->bank_name)
            || filled($profile->account_name)
            || filled($profile->account_number);

        if (! $hasActiveBankDetails) {
            $profile->update([
                'bank_name' => $validated['bank_name'],
                'bank_code' => $validated['bank_code'] ?? null,
                'account_name' => $validated['account_name'],
                'account_number' => $validated['account_number'],
                'payout_status' => 'approved',
            ]);

            return response()->json([
                'message' => 'Banking details saved successfully.',
                'freelancer_profile' => $profile->fresh(),
                'pending_bank_change' => null,
            ]);
        }

        $pendingChange = FreelancerBankChangeRequest::query()
            ->where('freelancer_id', $request->user()->id)
            ->where('status', 'pending')
            ->first();

        if ($pendingChange) {
            throw ValidationException::withMessages([
                'banking' => ['A banking change request is already waiting for admin approval.'],
            ]);
        }

        $pendingChange = FreelancerBankChangeRequest::query()->create([
            'freelancer_id' => $request->user()->id,
            'bank_name' => $validated['bank_name'],
            'bank_code' => $validated['bank_code'] ?? null,
            'account_name' => $validated['account_name'],
            'account_number' => $validated['account_number'],
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Banking change submitted for admin approval. Your current account remains active until it is reviewed.',
            'freelancer_profile' => $profile->fresh(),
            'pending_bank_change' => $pendingChange,
        ]);
    }
}
