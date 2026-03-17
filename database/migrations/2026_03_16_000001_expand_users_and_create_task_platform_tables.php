<?php

use App\Models\Campaign;
use App\Models\ClientProfile;
use App\Models\Device;
use App\Models\Dispute;
use App\Models\FraudFlag;
use App\Models\FreelancerProfile;
use App\Models\Notification;
use App\Models\PushToken;
use App\Models\ReviewDecision;
use App\Models\SubmissionProof;
use App\Models\TaskAssignment;
use App\Models\TaskSession;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role')->default('freelancer')->after('email');
            $table->string('status')->default('active')->after('role');
            $table->string('phone')->nullable()->after('password');
            $table->string('registration_ip', 45)->nullable()->after('phone');
            $table->string('registration_country', 2)->nullable()->after('registration_ip');
            $table->string('last_login_ip', 45)->nullable()->after('registration_country');
            $table->string('last_login_country', 2)->nullable()->after('last_login_ip');
            $table->string('timezone')->nullable()->after('last_login_country');
            $table->string('locale')->nullable()->after('timezone');
            $table->timestamp('last_seen_at')->nullable()->after('locale');

            $table->index(['role', 'status']);
        });

        Schema::create((new ClientProfile())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('avatar')->nullable();
            $table->string('default_country_target', 2)->nullable();
            $table->string('verification_status')->default('pending');
            $table->timestamps();
        });

        Schema::create((new FreelancerProfile())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('username')->nullable();
            $table->string('phone')->nullable();
            $table->string('avatar')->nullable();
            $table->string('payout_status')->default('pending');
            $table->decimal('trust_score', 5, 2)->default(0);
            $table->decimal('success_rate', 5, 2)->default(0);
            $table->unsignedInteger('total_completed')->default(0);
            $table->json('preferred_countries')->nullable();
            $table->string('verification_status')->default('pending');
            $table->timestamps();
        });

        Schema::create((new Device())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('device_identifier')->unique();
            $table->string('platform')->nullable();
            $table->string('model')->nullable();
            $table->string('app_version')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create((new PushToken())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(Device::class)->nullable()->constrained()->nullOnDelete();
            $table->string('provider')->default('firebase');
            $table->text('token');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create((new Campaign())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class, 'client_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('task_type');
            $table->text('target_url');
            $table->unsignedInteger('target_quantity');
            $table->unsignedInteger('completed_quantity')->default(0);
            $table->string('status')->default('draft');
            $table->string('review_mode')->default('hybrid');
            $table->string('proof_mode')->default('auto_and_manual');
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'task_type']);
        });

        Schema::create('campaign_targeting_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Campaign::class)->constrained()->cascadeOnDelete();
            $table->json('allowed_countries')->nullable();
            $table->json('blocked_countries')->nullable();
            $table->decimal('min_trust_score', 5, 2)->default(0);
            $table->unsignedInteger('max_assignments_per_freelancer')->default(1);
            $table->unsignedInteger('daily_assignment_limit')->nullable();
            $table->json('platform_constraints')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_pricings', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Campaign::class)->constrained()->cascadeOnDelete();
            $table->decimal('client_unit_price', 12, 2)->default(0);
            $table->decimal('freelancer_unit_payout', 12, 2)->default(0);
            $table->decimal('platform_margin', 12, 2)->default(0);
            $table->string('currency')->default('NGN');
            $table->decimal('payout_minimum_snapshot', 12, 2)->default(1000);
            $table->timestamps();
        });

        Schema::create((new Wallet())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('wallet_type');
            $table->string('currency')->default('NGN');
            $table->decimal('current_balance', 14, 2)->default(0);
            $table->decimal('pending_balance', 14, 2)->default(0);
            $table->decimal('withdrawable_balance', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'wallet_type', 'currency']);
        });

        Schema::create('client_campaign_funds', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Campaign::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'client_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('total_funded', 14, 2)->default(0);
            $table->decimal('total_reserved', 14, 2)->default(0);
            $table->decimal('total_spent', 14, 2)->default(0);
            $table->decimal('total_refunded', 14, 2)->default(0);
            $table->timestamps();
        });

        Schema::create((new TaskAssignment())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Campaign::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'freelancer_id')->constrained('users')->cascadeOnDelete();
            $table->string('assignment_code')->unique();
            $table->string('status')->default('queued');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->boolean('auto_assigned_by_rule')->default(true);
            $table->foreignIdFor(User::class, 'assigned_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->unique(['campaign_id', 'freelancer_id']);
        });

        Schema::create((new TaskSession())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(TaskAssignment::class, 'assignment_id')->constrained('task_assignments')->cascadeOnDelete();
            $table->text('opened_url')->nullable();
            $table->timestamp('webview_started_at')->nullable();
            $table->timestamp('webview_ended_at')->nullable();
            $table->unsignedInteger('screenshot_event_count')->default(0);
            $table->json('app_state_metadata')->nullable();
            $table->json('device_metadata')->nullable();
            $table->timestamps();
        });

        Schema::create((new TaskSubmission())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(TaskAssignment::class, 'assignment_id')->constrained('task_assignments')->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'freelancer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'client_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('submitted');
            $table->timestamp('submitted_at')->nullable();
            $table->string('client_decision')->nullable();
            $table->timestamp('client_decision_at')->nullable();
            $table->string('admin_decision')->nullable();
            $table->timestamp('admin_decision_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignIdFor(User::class, 'final_decision_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'submitted_at']);
        });

        Schema::create((new SubmissionProof())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(TaskSubmission::class, 'submission_id')->constrained('task_submissions')->cascadeOnDelete();
            $table->string('type');
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->string('source')->default('manual_upload');
            $table->timestamp('captured_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });

        Schema::create((new WithdrawalRequest())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class, 'freelancer_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('destination_type');
            $table->json('destination_details');
            $table->string('status')->default('requested');
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->foreignIdFor(User::class, 'processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'requested_at']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(Wallet::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('transaction_type');
            $table->string('direction');
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_before', 14, 2)->default(0);
            $table->decimal('balance_after', 14, 2)->default(0);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('status')->default('approved');
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['transaction_type', 'status']);
        });

        Schema::create((new ReviewDecision())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(TaskSubmission::class, 'submission_id')->constrained('task_submissions')->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'actor_id')->constrained('users')->cascadeOnDelete();
            $table->string('actor_role');
            $table->string('decision');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create((new Dispute())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(TaskSubmission::class, 'submission_id')->constrained('task_submissions')->cascadeOnDelete();
            $table->foreignIdFor(User::class, 'opened_by')->constrained('users')->cascadeOnDelete();
            $table->string('reason');
            $table->string('status')->default('open');
            $table->foreignIdFor(User::class, 'resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create((new Notification())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class)->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->text('body');
            $table->json('data_json')->nullable();
            $table->string('channel')->default('push');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('delivery_status')->default('pending');
            $table->timestamps();

            $table->index(['user_id', 'delivery_status']);
        });

        Schema::create((new FraudFlag())->getTable(), function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class)->nullable()->constrained()->nullOnDelete();
            $table->foreignIdFor(TaskAssignment::class, 'assignment_id')->nullable()->constrained('task_assignments')->nullOnDelete();
            $table->foreignIdFor(TaskSubmission::class, 'submission_id')->nullable()->constrained('task_submissions')->nullOnDelete();
            $table->string('type');
            $table->string('severity')->default('medium');
            $table->string('status')->default('open');
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->index(['severity', 'status']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignIdFor(User::class, 'actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role')->nullable();
            $table->string('action');
            $table->string('target_type');
            $table->unsignedBigInteger('target_id')->nullable();
            $table->json('before_json')->nullable();
            $table->json('after_json')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists((new FraudFlag())->getTable());
        Schema::dropIfExists((new Notification())->getTable());
        Schema::dropIfExists((new Dispute())->getTable());
        Schema::dropIfExists((new ReviewDecision())->getTable());
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists((new WithdrawalRequest())->getTable());
        Schema::dropIfExists((new SubmissionProof())->getTable());
        Schema::dropIfExists((new TaskSubmission())->getTable());
        Schema::dropIfExists((new TaskSession())->getTable());
        Schema::dropIfExists((new TaskAssignment())->getTable());
        Schema::dropIfExists('client_campaign_funds');
        Schema::dropIfExists((new Wallet())->getTable());
        Schema::dropIfExists('campaign_pricings');
        Schema::dropIfExists('campaign_targeting_rules');
        Schema::dropIfExists((new Campaign())->getTable());
        Schema::dropIfExists((new PushToken())->getTable());
        Schema::dropIfExists((new Device())->getTable());
        Schema::dropIfExists((new FreelancerProfile())->getTable());
        Schema::dropIfExists((new ClientProfile())->getTable());

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role', 'status']);
            $table->dropColumn([
                'role',
                'status',
                'phone',
                'registration_ip',
                'registration_country',
                'last_login_ip',
                'last_login_country',
                'timezone',
                'locale',
                'last_seen_at',
            ]);
        });
    }
};
