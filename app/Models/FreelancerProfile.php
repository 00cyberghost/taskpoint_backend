<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreelancerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'username',
        'phone',
        'avatar',
        'bank_name',
        'account_name',
        'account_number',
        'payout_status',
        'trust_score',
        'success_rate',
        'total_completed',
        'preferred_countries',
        'verification_status',
    ];

    protected function casts(): array
    {
        return [
            'preferred_countries' => 'array',
            'trust_score' => 'decimal:2',
            'success_rate' => 'decimal:2',
            'total_completed' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
