<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TaskSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'assignment_id',
        'freelancer_id',
        'client_id',
        'status',
        'submitted_at',
        'client_decision',
        'client_decision_at',
        'admin_decision',
        'admin_decision_at',
        'rejection_reason',
        'final_decision_by',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'client_decision_at' => 'datetime',
            'admin_decision_at' => 'datetime',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TaskAssignment::class, 'assignment_id');
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'freelancer_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function finalDecisionBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'final_decision_by');
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(SubmissionProof::class, 'submission_id');
    }

    public function reviewDecisions(): HasMany
    {
        return $this->hasMany(ReviewDecision::class, 'submission_id');
    }

    public function dispute(): HasOne
    {
        return $this->hasOne(Dispute::class, 'submission_id');
    }

    public function fraudFlags(): HasMany
    {
        return $this->hasMany(FraudFlag::class, 'submission_id');
    }
}
