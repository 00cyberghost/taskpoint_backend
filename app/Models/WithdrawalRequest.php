<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WithdrawalRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'freelancer_id',
        'amount',
        'destination_type',
        'destination_details',
        'status',
        'requested_at',
        'processed_at',
        'processed_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'destination_details' => 'array',
            'requested_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'freelancer_id');
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
