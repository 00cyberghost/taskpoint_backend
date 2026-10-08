<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientFundingRequest;
use App\Models\PlatformPaymentSetting;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use App\Services\NotificationService;
use App\Services\PayoutGatewayService;
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
        private readonly PayoutGatewayService $payoutGatewayService,
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
            'manual_enabled' => ['required', 'boolean'],
            'stripe_enabled' => ['required', 'boolean'],
            'paystack_enabled' => ['required', 'boolean'],
            'flutterwave_enabled' => ['required', 'boolean'],
            'monnify_enabled' => ['required', 'boolean'],
            'manual_bank_name' => ['nullable', 'string', 'max:255'],
            'manual_account_name' => ['nullable', 'string', 'max:255'],
            'manual_account_number' => ['nullable', 'string', 'max:50'],
            'stripe_public_key' => ['nullable', 'string', 'max:255'],
            'stripe_secret_key' => ['nullable', 'string', 'max:255'],
            'paystack_public_key' => ['nullable', 'string', 'max:255'],
            'paystack_secret_key' => ['nullable', 'string', 'max:255'],
            'flutterwave_public_key' => ['nullable', 'string', 'max:255'],
            'flutterwave_secret_key' => ['nullable', 'string', 'max:255'],
            'monnify_api_key' => ['nullable', 'string', 'max:255'],
            'monnify_secret_key' => ['nullable', 'string', 'max:255'],
            'monnify_contract_code' => ['nullable', 'string', 'max:100'],
            'monnify_environment' => ['required', 'string', 'in:sandbox,live'],
            'default_payout_method' => ['required', 'string', 'in:manual,paystack,flutterwave,monnify'],
            'monnify_disbursement_account_number' => ['nullable', 'string', 'max:50'],
            'flutterwave_webhook_secret' => ['nullable', 'string', 'max:255'],
        ]);

        $enabledMethods = collect([
            'manual' => (bool) $validated['manual_enabled'],
            'stripe' => (bool) $validated['stripe_enabled'],
            'paystack' => (bool) $validated['paystack_enabled'],
            'flutterwave' => (bool) $validated['flutterwave_enabled'],
            'monnify' => (bool) $validated['monnify_enabled'],
        ])->filter()->keys()->values();

        if ($enabledMethods->isEmpty()) {
            throw ValidationException::withMessages([
                'manual_enabled' => ['At least one payment method must remain enabled.'],
            ]);
        }

        $keyErrors = [];
        $payoutMethod = $validated['default_payout_method'];

        if ($payoutMethod !== 'manual' && ! $enabledMethods->contains($payoutMethod)) {
            $keyErrors['default_payout_method'] = ['Enable the selected gateway before using it for freelancer payouts.'];
        }

        if ((bool) $validated['paystack_enabled'] && filled($validated['paystack_public_key'] ?? null) && ! preg_match('/^pk_(test|live)_/', (string) $validated['paystack_public_key'])) {
            $keyErrors['paystack_public_key'] = ['Paystack public keys must start with pk_test_ or pk_live_.'];
        }

        if ((bool) $validated['paystack_enabled'] && filled($validated['paystack_secret_key'] ?? null) && ! preg_match('/^sk_(test|live)_/', (string) $validated['paystack_secret_key'])) {
            $keyErrors['paystack_secret_key'] = ['Paystack secret keys must start with sk_test_ or sk_live_.'];
        }

        if ($payoutMethod === 'paystack' && blank($validated['paystack_secret_key'] ?? null)) {
            $keyErrors['default_payout_method'] = ['Add a Paystack secret key before selecting Paystack for payouts.'];
        }

        if ($payoutMethod === 'flutterwave' && blank($validated['flutterwave_secret_key'] ?? null)) {
            $keyErrors['default_payout_method'] = ['Add a Flutterwave secret key before selecting Flutterwave for payouts.'];
        }

        if ($payoutMethod === 'monnify' && (blank($validated['monnify_api_key'] ?? null) || blank($validated['monnify_secret_key'] ?? null) || blank($validated['monnify_disbursement_account_number'] ?? null))) {
            $keyErrors['default_payout_method'] = ['Add Monnify API credentials and the disbursement source account before selecting Monnify for payouts.'];
        }

        if ($keyErrors !== []) {
            throw ValidationException::withMessages($keyErrors);
        }

        $validated['active_method'] = $enabledMethods->contains('manual') ? 'manual' : 'automatic';
        $validated['automatic_methods'] = $enabledMethods->reject(fn (string $method) => $method === 'manual')->values()->all();

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

        if ($validated['status'] === 'paid') {
            $message = $this->payoutGatewayService->process($withdrawal, $request->user()?->id);

            return back()->with('success', $message);
        }

        DB::transaction(function () use ($request, $withdrawal, $validated): void {
            $withdrawal->update([
                'status' => $validated['status'],
                'processed_by' => $request->user()?->id,
                'processed_at' => now(),
            ]);
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
