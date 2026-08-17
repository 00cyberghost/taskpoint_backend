<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_payment_settings', function (Blueprint $table): void {
            $table->boolean('manual_enabled')->default(true)->after('active_method');
            $table->boolean('stripe_enabled')->default(false)->after('manual_enabled');
            $table->boolean('paystack_enabled')->default(false)->after('stripe_enabled');
            $table->boolean('flutterwave_enabled')->default(false)->after('paystack_enabled');
            $table->text('stripe_public_key')->nullable()->after('automatic_methods');
            $table->text('stripe_secret_key')->nullable()->after('stripe_public_key');
            $table->text('paystack_public_key')->nullable()->after('stripe_secret_key');
            $table->text('paystack_secret_key')->nullable()->after('paystack_public_key');
            $table->text('flutterwave_public_key')->nullable()->after('paystack_secret_key');
            $table->text('flutterwave_secret_key')->nullable()->after('flutterwave_public_key');
        });

        Schema::table('client_funding_requests', function (Blueprint $table): void {
            $table->string('provider_reference')->nullable()->after('payment_method');
            $table->text('checkout_url')->nullable()->after('provider_reference');
            $table->json('provider_payload')->nullable()->after('checkout_url');
            $table->timestamp('paid_at')->nullable()->after('reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('client_funding_requests', function (Blueprint $table): void {
            $table->dropColumn([
                'provider_reference',
                'checkout_url',
                'provider_payload',
                'paid_at',
            ]);
        });

        Schema::table('platform_payment_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'manual_enabled',
                'stripe_enabled',
                'paystack_enabled',
                'flutterwave_enabled',
                'stripe_public_key',
                'stripe_secret_key',
                'paystack_public_key',
                'paystack_secret_key',
                'flutterwave_public_key',
                'flutterwave_secret_key',
            ]);
        });
    }
};
