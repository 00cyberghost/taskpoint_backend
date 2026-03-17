<?php

namespace App\Support;

use App\Models\User;

class ClientWalletAvailability
{
    public static function available(User $client): float
    {
        $wallet = $client->wallets()
            ->where('wallet_type', 'client_main')
            ->where('currency', 'NGN')
            ->first();

        $reserved = (float) $client->clientCampaigns()
            ->with('funds:id,campaign_id,total_reserved')
            ->get()
            ->sum(fn ($campaign) => (float) ($campaign->funds?->total_reserved ?? 0));

        return max(((float) ($wallet?->current_balance ?? 0)) - $reserved, 0);
    }
}
