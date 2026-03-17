<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TaskAssignment extends Model
{
    use HasFactory;

    protected $fillable = [
        'campaign_id',
        'freelancer_id',
        'assignment_code',
        'status',
        'assigned_at',
        'started_at',
        'expires_at',
        'submitted_at',
        'approved_at',
        'rejected_at',
        'auto_assigned_by_rule',
        'assigned_by_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
            'auto_assigned_by_rule' => 'boolean',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'freelancer_id');
    }

    public function assignedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by_admin_id');
    }

    public function session(): HasOne
    {
        return $this->hasOne(TaskSession::class, 'assignment_id');
    }

    public function submission(): HasOne
    {
        return $this->hasOne(TaskSubmission::class, 'assignment_id');
    }

    public function fraudFlags(): HasMany
    {
        return $this->hasMany(FraudFlag::class, 'assignment_id');
    }
}
