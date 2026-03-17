<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Validation\ValidationException;

class WalletLedgerService
{
    public function walletFor(User|int $user, string $walletType, string $currency = 'NGN'): Wallet
    {
        return Wallet::query()->firstOrCreate(
            [
                'user_id' => $user instanceof User ? $user->id : $user,
                'wallet_type' => $walletType,
                'currency' => $currency,
            ],
            [
                'current_balance' => 0,
                'pending_balance' => 0,
                'withdrawable_balance' => 0,
            ],
        );
    }

    public function credit(
        Wallet $wallet,
        float $amount,
        array $transactionAttributes,
        float $pendingDelta = 0,
        ?float $withdrawableDelta = null,
    ): WalletTransaction {
        $before = (float) $wallet->current_balance;
        $after = $before + $amount;

        $wallet->update([
            'current_balance' => $after,
            'pending_balance' => (float) $wallet->pending_balance + $pendingDelta,
            'withdrawable_balance' => (float) $wallet->withdrawable_balance + ($withdrawableDelta ?? $amount),
        ]);

        return WalletTransaction::query()->create([
            ...$transactionAttributes,
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'direction' => 'credit',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
        ]);
    }

    public function debit(
        Wallet $wallet,
        float $amount,
        array $transactionAttributes,
        float $pendingDelta = 0,
        ?float $withdrawableDelta = null,
        bool $enforceFunds = true,
    ): WalletTransaction {
        $before = (float) $wallet->current_balance;

        if ($enforceFunds && $before < $amount) {
            throw ValidationException::withMessages([
                'wallet' => ['Insufficient wallet balance for this operation.'],
            ]);
        }

        $after = max($before - $amount, 0);

        $wallet->update([
            'current_balance' => $after,
            'pending_balance' => max((float) $wallet->pending_balance + $pendingDelta, 0),
            'withdrawable_balance' => max((float) $wallet->withdrawable_balance + ($withdrawableDelta ?? -$amount), 0),
        ]);

        return WalletTransaction::query()->create([
            ...$transactionAttributes,
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'direction' => 'debit',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $after,
        ]);
    }

    public function recordMeta(Wallet $wallet, float $amount, array $transactionAttributes): WalletTransaction
    {
        $balance = (float) $wallet->current_balance;

        return WalletTransaction::query()->create([
            ...$transactionAttributes,
            'wallet_id' => $wallet->id,
            'user_id' => $wallet->user_id,
            'direction' => 'meta',
            'amount' => $amount,
            'balance_before' => $balance,
            'balance_after' => $balance,
        ]);
    }
}
