<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformPaymentSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'active_method',
        'manual_bank_name',
        'manual_account_name',
        'manual_account_number',
        'automatic_methods',
    ];

    protected function casts(): array
    {
        return [
            'automatic_methods' => 'array',
        ];
    }
}
