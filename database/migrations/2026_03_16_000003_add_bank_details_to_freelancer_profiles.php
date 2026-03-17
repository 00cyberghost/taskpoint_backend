<?php

use App\Models\FreelancerProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new FreelancerProfile())->getTable(), function (Blueprint $table): void {
            $table->string('bank_name')->nullable()->after('avatar');
            $table->string('account_name')->nullable()->after('bank_name');
            $table->string('account_number', 50)->nullable()->after('account_name');
        });
    }

    public function down(): void
    {
        Schema::table((new FreelancerProfile())->getTable(), function (Blueprint $table): void {
            $table->dropColumn(['bank_name', 'account_name', 'account_number']);
        });
    }
};
