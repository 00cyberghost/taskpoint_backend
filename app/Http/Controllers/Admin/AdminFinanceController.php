<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientFundingRequest;
use App\Models\PlatformPaymentSetting;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use App\Services\NotificationService;
use App\Services\WalletLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminFinanceController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly WalletLedgerService $walletLedgerService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin-finance', [
            'paymentSetting' => PlatformPaymentSetting::query()->first(),
            'withdrawals' => WithdrawalRequest::query()
                ->with('freelancer:id,name,email')
                ->latest('requested_at')
                ->paginate(12)
                ->withQueryString(),
            'fundingRequests' => ClientFundingRequest::query()
                ->with('client:id,name,email')
                ->latest('submitted_at')
                ->take(20)
                ->get(),
            'transactions' => WalletTransaction::query()
                ->with('user:id,name,email')
                ->latest()
                ->take(20)
                ->get(),
        ]);
    }

    public function updatePaymentSetting(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'active_method' => ['required', 'string', 'in:manual,automatic'],
            'manual_bank_name' => ['nullable', 'string', 'max:255'],
            'manual_account_name' => ['nullable', 'string', 'max:255'],
            'manual_account_number' => ['nullable', 'string', 'max:50'],
        ]);

        PlatformPaymentSetting::query()->updateOrCreate(
            ['id' => PlatformPaymentSetting::query()->value('id') ?? 1],
            $validated,
        );

        return back()->with('success', 'Payment settings updated.');
    }

    public function updateWithdrawal(Request $request, WithdrawalRequest $withdrawal): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:under_review,approved,processing,paid,rejected'],
        ]);

        if ($withdrawal->status === $validated['status']) {
            return back()->with('success', 'Withdrawal status already set.');
        }

        if ($withdrawal->status === 'paid') {
            throw ValidationException::withMessages([
                'status' => ['A paid withdrawal cannot be changed again.'],
            ]);
        }

        DB::transaction(function () use ($request, $withdrawal, $validated): void {
            $withdrawal->update([
                'status' => $validated['status'],
                'processed_by' => $request->user()?->id,
                'processed_at' => now(),
            ]);

            if ($validated['status'] !== 'paid') {
                return;
            }

            $wallet = $this->walletLedgerService->walletFor($withdrawal->freelancer_id, 'freelancer_main');

            if ((float) $wallet->withdrawable_balance < (float) $withdrawal->amount) {
                throw ValidationException::withMessages([
                    'status' => ['The freelancer does not have enough withdrawable balance to settle this payout.'],
                ]);
            }

            $this->walletLedgerService->debit($wallet, (float) $withdrawal->amount, [
                'transaction_type' => 'withdrawal_payout',
                'reference_type' => WithdrawalRequest::class,
                'reference_id' => $withdrawal->id,
                'status' => 'paid',
                'description' => 'Withdrawal settled by admin payout operations.',
            ], 0, -(float) $withdrawal->amount);

            $this->notificationService->create(
                $withdrawal->freelancer_id,
                'withdrawal_paid',
                'Withdrawal paid',
                'Your withdrawal request has been marked as paid by the admin team.',
                [
                    'withdrawal_id' => $withdrawal->id,
                    'amount' => $withdrawal->amount,
                ],
            );
        });

        return back()->with('success', 'Withdrawal status updated.');
    }

    public function updateFundingRequest(Request $request, ClientFundingRequest $fundingRequest): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:approved,rejected'],
        ]);

        if ($fundingRequest->status === $validated['status']) {
            return back()->with('success', 'Funding request already has this status.');
        }

        if ($fundingRequest->status === 'approved') {
            throw ValidationException::withMessages([
                'status' => ['An approved funding request cannot be changed again.'],
            ]);
        }

        DB::transaction(function () use ($request, $fundingRequest, $validated): void {
            $fundingRequest->update([
                'status' => $validated['status'],
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => now(),
            ]);

            if ($validated['status'] !== 'approved') {
                return;
            }

            $wallet = $this->walletLedgerService->walletFor($fundingRequest->client_id, 'client_main');

            $this->walletLedgerService->credit($wallet, (float) $fundingRequest->amount, [
                'transaction_type' => 'client_wallet_funding',
                'reference_type' => ClientFundingRequest::class,
                'reference_id' => $fundingRequest->id,
                'status' => 'approved',
                'description' => 'Manual wallet funding approved by admin.',
            ], 0, 0);

            $this->notificationService->create(
                $fundingRequest->client_id,
                'wallet_funding_approved',
                'Wallet funding approved',
                'Your wallet funding request has been approved and your balance was updated.',
                [
                    'funding_request_id' => $fundingRequest->id,
                    'amount' => $fundingRequest->amount,
                ],
            );
        });

        return back()->with('success', 'Funding request updated.');
    }
}
