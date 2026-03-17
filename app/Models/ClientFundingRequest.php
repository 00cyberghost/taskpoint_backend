<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClientFundingRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'amount',
        'payment_method',
        'status',
        'note',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
