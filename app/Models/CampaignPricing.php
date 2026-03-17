<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignPricing extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'client_unit_price',
        'freelancer_unit_payout',
        'platform_margin',
        'currency',
        'payout_minimum_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'client_unit_price' => 'decimal:2',
            'freelancer_unit_payout' => 'decimal:2',
            'platform_margin' => 'decimal:2',
            'payout_minimum_snapshot' => 'decimal:2',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
