<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewDecision extends Model
{
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'actor_id',
        'actor_role',
        'decision',
        'note',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(TaskSubmission::class, 'submission_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
