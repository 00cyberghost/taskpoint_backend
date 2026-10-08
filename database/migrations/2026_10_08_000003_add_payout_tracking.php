<?php

use App\Models\FreelancerProfile;
use App\Models\WithdrawalRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new FreelancerProfile)->getTable(), function (Blueprint $table): void {
            $table->string('bank_code', 20)->nullable()->after('bank_name');
        });

        Schema::table((new WithdrawalRequest)->getTable(), function (Blueprint $table): void {
            $table->string('payout_method')->nullable()->after('destination_type');
            $table->string('provider_reference')->nullable()->after('payout_method');
            $table->json('provider_payload')->nullable()->after('provider_reference');
            $table->text('failure_reason')->nullable()->after('provider_payload');
            $table->index(['payout_method', 'provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::table((new WithdrawalRequest)->getTable(), function (Blueprint $table): void {
            $table->dropIndex(['payout_method', 'provider_reference']);
            $table->dropColumn([
                'payout_method',
                'provider_reference',
                'provider_payload',
                'failure_reason',
            ]);
        });

        Schema::table((new FreelancerProfile)->getTable(), function (Blueprint $table): void {
            $table->dropColumn('bank_code');
        });
    }
};
