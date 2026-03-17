<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'title',
        'body',
        'image_path',
        'data_json',
        'channel',
        'read_at',
        'sent_at',
        'delivery_status',
    ];

    protected $appends = [
        'image_url',
    ];

    protected function casts(): array
    {
        return [
            'data_json' => 'array',
            'read_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image_path) {
            return null;
        }

        return url(Storage::disk('public')->url($this->image_path));
    }
}
