<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Campaign extends Model
{
    use HasFactory;

    protected $fillable = [
        'client_id',
        'title',
        'description',
        'task_type',
        'target_url',
        'target_quantity',
        'completed_quantity',
        'status',
        'review_mode',
        'proof_mode',
        'start_at',
        'end_at',
    ];

    protected function casts(): array
    {
        return [
            'target_quantity' => 'integer',
            'completed_quantity' => 'integer',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function targetingRule(): HasOne
    {
        return $this->hasOne(CampaignTargetingRule::class);
    }

    public function pricing(): HasOne
    {
        return $this->hasOne(CampaignPricing::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(TaskAssignment::class);
    }

    public function funds(): HasOne
    {
        return $this->hasOne(ClientCampaignFund::class);
    }
}
