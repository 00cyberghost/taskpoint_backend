<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_payment_settings', function (Blueprint $table): void {
            $table->boolean('monnify_enabled')->default(false)->after('flutterwave_enabled');
            $table->text('monnify_api_key')->nullable()->after('flutterwave_secret_key');
            $table->text('monnify_secret_key')->nullable()->after('monnify_api_key');
            $table->string('monnify_contract_code')->nullable()->after('monnify_secret_key');
            $table->string('monnify_environment')->default('sandbox')->after('monnify_contract_code');
        });
    }

    public function down(): void
    {
        Schema::table('platform_payment_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'monnify_enabled',
                'monnify_api_key',
                'monnify_secret_key',
                'monnify_contract_code',
                'monnify_environment',
            ]);
        });
    }
};
