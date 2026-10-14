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
        $tableName = (new FreelancerBankChangeRequest)->getTable();

        if (! Schema::hasTable($tableName)) {
            Schema::create($tableName, function (Blueprint $table): void {
                $table->id();
                $table->foreignIdFor(User::class, 'freelancer_id')->constrained('users')->cascadeOnDelete();
                $table->string('bank_name');
                $table->string('bank_code', 20)->nullable();
                $table->string('account_name');
                $table->string('account_number', 50);
                $table->string('status', 32)->default('pending');
                $table->text('rejection_reason')->nullable();
                $table->timestamp('reviewed_at')->nullable();
                $table->foreignIdFor(User::class, 'reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['freelancer_id', 'status'], 'fbr_freelancer_status_idx');
            });

            return;
        }

        // A failed deployment can leave the table created but the migration unrecorded.
        $existingStatusIndex = collect(Schema::getIndexes($tableName))
            ->first(fn (array $index): bool => $index['columns'] === ['freelancer_id', 'status']);

        if ($existingStatusIndex) {
            Schema::table($tableName, function (Blueprint $table) use ($existingStatusIndex): void {
                $table->dropIndex($existingStatusIndex['name']);
            });
        }

        Schema::table($tableName, function (Blueprint $table): void {
            $table->string('status', 32)->default('pending')->change();
        });

        Schema::table($tableName, function (Blueprint $table): void {
            $table->index(['freelancer_id', 'status'], 'fbr_freelancer_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists((new FreelancerBankChangeRequest)->getTable());
    }
};
