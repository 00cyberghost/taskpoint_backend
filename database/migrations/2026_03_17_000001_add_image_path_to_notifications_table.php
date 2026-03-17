<?php

use App\Models\Notification;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new Notification())->getTable(), function (Blueprint $table): void {
            $table->string('image_path')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table((new Notification())->getTable(), function (Blueprint $table): void {
            $table->dropColumn('image_path');
        });
    }
};
