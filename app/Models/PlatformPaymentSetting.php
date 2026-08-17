<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlatformPaymentSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'active_method',
        'manual_enabled',
        'stripe_enabled',
        'paystack_enabled',
        'flutterwave_enabled',
        'manual_bank_name',
        'manual_account_name',
        'manual_account_number',
        'automatic_methods',
        'stripe_public_key',
        'stripe_secret_key',
        'paystack_public_key',
        'paystack_secret_key',
        'flutterwave_public_key',
        'flutterwave_secret_key',
    ];

    protected function casts(): array
    {
        return [
            'automatic_methods' => 'array',
            'manual_enabled' => 'boolean',
            'stripe_enabled' => 'boolean',
            'paystack_enabled' => 'boolean',
            'flutterwave_enabled' => 'boolean',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function enabledMethods(): array
    {
        $methods = [];

        if ($this->manual_enabled) {
            $methods[] = 'manual';
        }

        if ($this->stripe_enabled) {
            $methods[] = 'stripe';
        }

        if ($this->paystack_enabled) {
            $methods[] = 'paystack';
        }

        if ($this->flutterwave_enabled) {
            $methods[] = 'flutterwave';
        }

        return $methods;
    }
}
