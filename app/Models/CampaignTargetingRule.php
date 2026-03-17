<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CampaignTargetingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'allowed_countries',
        'blocked_countries',
        'min_trust_score',
        'max_assignments_per_freelancer',
        'daily_assignment_limit',
        'platform_constraints',
    ];

    protected function casts(): array
    {
        return [
            'allowed_countries' => 'array',
            'blocked_countries' => 'array',
            'platform_constraints' => 'array',
            'min_trust_score' => 'decimal:2',
            'max_assignments_per_freelancer' => 'integer',
            'daily_assignment_limit' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
