<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dispute extends Model
{
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'opened_by',
        'reason',
        'status',
        'resolved_by',
        'resolution_note',
        'opened_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(TaskSubmission::class, 'submission_id');
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
