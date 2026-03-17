<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'assignment_id',
        'opened_url',
        'webview_started_at',
        'webview_ended_at',
        'screenshot_event_count',
        'app_state_metadata',
        'device_metadata',
    ];

    protected function casts(): array
    {
        return [
            'webview_started_at' => 'datetime',
            'webview_ended_at' => 'datetime',
            'screenshot_event_count' => 'integer',
            'app_state_metadata' => 'array',
            'device_metadata' => 'array',
        ];
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(TaskAssignment::class, 'assignment_id');
    }
}
