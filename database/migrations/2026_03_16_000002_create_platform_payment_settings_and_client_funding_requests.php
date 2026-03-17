<?php

use App\Models\ClientFundingRequest;
use App\Models\PlatformPaymentSetting;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((new PlatformPaymentSetting())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->string('active_method')->default('manual');
            $table->string('manual_bank_name')->nullable();
            $table->string('manual_account_name')->nullable();
            $table->string('manual_account_number')->nullable();
            $table->json('automatic_methods')->nullable();
            $table->timestamps();
        });

        Schema::create((new ClientFundingRequest())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class, 'client_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('payment_method')->default('manual');
            $table->string('status')->default('pending');
            $table->text('note')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignIdFor(User::class, 'reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'submitted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((new ClientFundingRequest())->getTable());
        Schema::dropIfExists((new PlatformPaymentSetting())->getTable());
    }
};
