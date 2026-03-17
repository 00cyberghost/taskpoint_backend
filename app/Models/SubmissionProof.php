<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionProof extends Model
{
    use HasFactory;

    protected $fillable = [
        'submission_id',
        'type',
        'file_path',
        'mime_type',
        'source',
        'captured_at',
        'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'metadata_json' => 'array',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(TaskSubmission::class, 'submission_id');
    }
}
