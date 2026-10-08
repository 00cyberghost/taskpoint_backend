<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_payment_settings', function (Blueprint $table): void {
            $table->string('default_payout_method')->default('manual')->after('monnify_environment');
            $table->string('monnify_disbursement_account_number')->nullable()->after('default_payout_method');
            $table->text('flutterwave_webhook_secret')->nullable()->after('flutterwave_secret_key');
        });
    }

    public function down(): void
    {
        Schema::table('platform_payment_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'default_payout_method',
                'monnify_disbursement_account_number',
                'flutterwave_webhook_secret',
            ]);
        });
    }
};
