<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FraudFlag extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'assignment_id',
        'submission_id',
        'type',
        'severity',
        'status',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TaskAssignment::class, 'assignment_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(TaskSubmission::class, 'submission_id');
    }
}
