<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaskTypePricing extends Model
{
    use HasFactory;

    protected $fillable = [
        'task_type',
        'client_unit_price',
        'freelancer_unit_payout',
        'currency',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'client_unit_price' => 'decimal:2',
            'freelancer_unit_payout' => 'decimal:2',
            'active' => 'boolean',
        ];
    }
}
