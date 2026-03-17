<?php

use App\Models\TaskTypePricing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create((new TaskTypePricing())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->string('task_type')->unique();
            $table->decimal('client_unit_price', 12, 2)->default(0);
            $table->decimal('freelancer_unit_payout', 12, 2)->default(0);
            $table->string('currency')->default('NGN');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((new TaskTypePricing())->getTable());
    }
};
