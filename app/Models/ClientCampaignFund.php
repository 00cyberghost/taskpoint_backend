<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientCampaignFund extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'client_id',
        'total_funded',
        'total_reserved',
        'total_spent',
        'total_refunded',
    ];

    protected function casts(): array
    {
        return [
            'total_funded' => 'decimal:2',
            'total_reserved' => 'decimal:2',
            'total_spent' => 'decimal:2',
            'total_refunded' => 'decimal:2',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }
}
