<?php

use App\Models\FreelancerBankChangeRequest;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((new FreelancerBankChangeRequest)->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class, 'freelancer_id')->constrained('users')->cascadeOnDelete();
            $table->string('bank_name');
            $table->string('bank_code', 20)->nullable();
            $table->string('account_name');
            $table->string('account_number', 50);
            $table->string('status')->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignIdFor(User::class, 'reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['freelancer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((new FreelancerBankChangeRequest)->getTable());
    }
};
