<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'actor_id',
        'actor_role',
        'action',
        'target_type',
        'target_id',
        'before_json',
        'after_json',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'before_json' => 'array',
            'after_json' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
