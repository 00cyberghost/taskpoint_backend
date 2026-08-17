<?php

use App\Models\Campaign;
use App\Models\CampaignPricing;
use App\Models\ClientProfile;
use App\Models\FreelancerProfile;
use App\Models\EmailVerificationOtp;
use App\Models\Notification;
use App\Models\SubmissionProof;
use App\Models\TaskAssignment;
use App\Models\TaskSubmission;
use App\Models\TaskTypePricing;
use App\Models\User;
use App\Models\Wallet;
use App\Services\GoogleIdentityService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Laravel\Sanctum\Sanctum;
use App\Mail\EmailVerificationOtpMail;

it('registers a freelancer with profile and location metadata', function () {
    Mail::fake();

    $response = $this
        ->withHeaders(['CF-IPCountry' => 'NG'])
        ->postJson('/api/auth/register', [
            'name' => 'Ada Worker',
            'email' => 'ada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'freelancer',
        ]);

    $response
        ->assertCreated()
        ->assertJsonPath('user.role', 'freelancer')
        ->assertJsonStructure(['token', 'user' => ['id', 'email']]);

    $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

    expect($user->registration_country)->toBe('NG');
    expect(FreelancerProfile::query()->where('user_id', $user->id)->exists())->toBeTrue();
    expect(EmailVerificationOtp::query()->where('user_id', $user->id)->exists())->toBeTrue();
    Mail::assertSent(EmailVerificationOtpMail::class);
});

it('resends verification otp and blocks unverified freelancers from protected platform routes', function () {
    Mail::fake();

    $freelancer = User::factory()->unverified()->create([
        'role' => 'freelancer',
        'status' => 'active',
    ]);

    FreelancerProfile::query()->create(['user_id' => $freelancer->id]);

    Sanctum::actingAs($freelancer);

    $this->postJson('/api/auth/email/verification-notification')
        ->assertOk()
        ->assertJsonPath('message', 'Verification code sent successfully.');

    Mail::assertSent(EmailVerificationOtpMail::class);

    $this->getJson('/api/freelancer/dashboard')
        ->assertForbidden()
        ->assertJsonPath('message', 'Please verify your email address before using the platform.');
});

it('verifies a freelancer email with otp', function () {
    $freelancer = User::factory()->unverified()->create([
        'role' => 'freelancer',
        'status' => 'active',
    ]);

    FreelancerProfile::query()->create(['user_id' => $freelancer->id]);

    EmailVerificationOtp::query()->create([
        'user_id' => $freelancer->id,
        'code_hash' => bcrypt('123456'),
        'attempts' => 0,
        'expires_at' => now()->addMinutes(10),
        'sent_at' => now(),
    ]);

    Sanctum::actingAs($freelancer);

    $this->postJson('/api/auth/email/verify-otp', [
        'otp' => '123456',
    ])->assertOk()->assertJsonPath('message', 'Email verified successfully.');

    expect($freelancer->fresh()->hasVerifiedEmail())->toBeTrue();
    expect(EmailVerificationOtp::query()->where('user_id', $freelancer->id)->exists())->toBeFalse();
});

it('blocks suspended freelancer accounts from login and registration reuse', function () {
    $suspended = User::factory()->create([
        'role' => 'freelancer',
        'status' => 'suspended',
        'email' => 'blocked-freelancer@example.com',
        'registration_ip' => '203.0.113.20',
        'last_login_ip' => '203.0.113.20',
    ]);

    FreelancerProfile::query()->create(['user_id' => $suspended->id]);

    $this->postJson('/api/auth/login', [
        'email' => 'blocked-freelancer@example.com',
        'password' => 'password',
    ])->assertUnprocessable()->assertJsonValidationErrors(['email']);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
        ->postJson('/api/auth/register', [
            'name' => 'Blocked Attempt',
            'email' => 'new-freelancer@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'freelancer',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email']);
});

it('logs a freelancer in with google and auto-verifies the email', function () {
    app()->instance(GoogleIdentityService::class, new class extends GoogleIdentityService
    {
        public function verify(string $idToken): array
        {
            expect($idToken)->toBe('google-token');

            return [
                'sub' => 'google-freelancer-123',
                'email' => 'google.freelancer@example.com',
                'email_verified' => true,
                'name' => 'Google Freelancer',
            ];
        }
    });

    $response = $this->postJson('/api/auth/google', [
        'id_token' => 'google-token',
        'role' => 'freelancer',
        'device_name' => 'android-test',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('user.role', 'freelancer')
        ->assertJsonPath('user.email', 'google.freelancer@example.com');

    $user = User::query()->where('email', 'google.freelancer@example.com')->firstOrFail();

    expect($user->google_id)->toBe('google-freelancer-123');
    expect($user->hasVerifiedEmail())->toBeTrue();
    expect(FreelancerProfile::query()->where('user_id', $user->id)->exists())->toBeTrue();
});

it('emails a password reset link for a freelancer account', function () {
    NotificationFacade::fake();

    $freelancer = User::factory()->create([
        'role' => 'freelancer',
        'email' => 'freelancer-reset@example.com',
    ]);
    FreelancerProfile::query()->create(['user_id' => $freelancer->id]);

    $this->postJson('/api/auth/forgot-password', [
        'email' => $freelancer->email,
    ])->assertOk()->assertJsonPath('message', 'Password reset link sent successfully.');

    NotificationFacade::assertSentTo($freelancer, ResetPassword::class);
});

it('starts and submits an assigned task with proof uploads', function () {
    Storage::fake('public');

    $client = User::factory()->create(['role' => 'client']);
    ClientProfile::query()->create(['user_id' => $client->id]);

    $freelancer = User::factory()->create(['role' => 'freelancer']);
    FreelancerProfile::query()->create(['user_id' => $freelancer->id]);

    $campaign = Campaign::query()->create([
        'client_id' => $client->id,
        'title' => 'YouTube Comments Burst',
        'description' => 'Leave a short positive comment.',
        'task_type' => 'comment',
        'target_url' => 'https://youtube.com/watch?v=example123',
        'target_quantity' => 10,
        'status' => 'active',
    ]);

    CampaignPricing::query()->create([
        'campaign_id' => $campaign->id,
        'client_unit_price' => 150,
        'freelancer_unit_payout' => 100,
        'platform_margin' => 50,
        'currency' => 'NGN',
    ]);

    $assignment = TaskAssignment::query()->create([
        'campaign_id' => $campaign->id,
        'freelancer_id' => $freelancer->id,
        'assignment_code' => 'TASK-1001',
        'status' => 'assigned',
        'assigned_at' => now(),
        'expires_at' => now()->addHours(4),
    ]);

    Sanctum::actingAs($freelancer);

    $this->postJson("/api/freelancer/tasks/{$assignment->id}/start", [
        'opened_url' => $campaign->target_url,
        'device_metadata' => ['platform' => 'ios'],
    ])->assertOk()->assertJsonPath('assignment.status', 'in_progress');

    $response = $this->post("/api/freelancer/tasks/{$assignment->id}/submit", [
        'note' => 'Completed and verified.',
        'opened_url' => $campaign->target_url,
        'screenshot_event_count' => 1,
        'auto_proof' => UploadedFile::fake()->image('auto-proof.png'),
        'manual_proof' => UploadedFile::fake()->image('manual-proof.png'),
    ]);

    $response->assertOk()->assertJsonPath('message', 'Proof submitted successfully.');

    expect(TaskSubmission::query()->where('assignment_id', $assignment->id)->exists())->toBeTrue();
    expect(SubmissionProof::query()->count())->toBe(2);
    expect(Notification::query()->where('user_id', $client->id)->where('type', 'submission_received')->exists())->toBeTrue();
    expect(Notification::query()->where('user_id', $freelancer->id)->where('type', 'submission_sent')->exists())->toBeTrue();
});

it('approves a submission and posts wallet entries for both sides', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $client = User::factory()->create(['role' => 'client']);
    $freelancer = User::factory()->create(['role' => 'freelancer']);

    ClientProfile::query()->create(['user_id' => $client->id]);
    $freelancerProfile = FreelancerProfile::query()->create(['user_id' => $freelancer->id]);

    $campaign = Campaign::query()->create([
        'client_id' => $client->id,
        'title' => 'Instagram Likes',
        'description' => 'Like the target post.',
        'task_type' => 'like',
        'target_url' => 'https://instagram.com/p/example123',
        'target_quantity' => 20,
        'status' => 'active',
    ]);

    CampaignPricing::query()->create([
        'campaign_id' => $campaign->id,
        'client_unit_price' => 200,
        'freelancer_unit_payout' => 120,
        'platform_margin' => 80,
        'currency' => 'NGN',
    ]);

    Wallet::query()->create([
        'user_id' => $client->id,
        'wallet_type' => 'client_main',
        'currency' => 'NGN',
        'current_balance' => 5000,
        'pending_balance' => 0,
        'withdrawable_balance' => 0,
    ]);

    $assignment = TaskAssignment::query()->create([
        'campaign_id' => $campaign->id,
        'freelancer_id' => $freelancer->id,
        'assignment_code' => 'TASK-1002',
        'status' => 'submitted',
        'assigned_at' => now(),
        'started_at' => now()->subMinutes(5),
        'submitted_at' => now(),
    ]);

    $submission = TaskSubmission::query()->create([
        'assignment_id' => $assignment->id,
        'freelancer_id' => $freelancer->id,
        'client_id' => $client->id,
        'status' => 'client_review',
        'submitted_at' => now(),
    ]);

    $this
        ->actingAs($admin)
        ->from('/admin/reviews')
        ->post("/admin/reviews/{$submission->id}/approve")
        ->assertRedirect('/admin/reviews');

    expect($submission->fresh()->status)->toBe('approved');
    expect($campaign->fresh()->completed_quantity)->toBe(1);
    expect($freelancerProfile->fresh()->total_completed)->toBe(1);
    expect((float) Wallet::query()->where('user_id', $freelancer->id)->where('wallet_type', 'freelancer_main')->firstOrFail()->current_balance)->toBe(120.0);
    expect((float) Wallet::query()->where('user_id', $client->id)->where('wallet_type', 'client_main')->firstOrFail()->current_balance)->toBe(4800.0);
    expect(Notification::query()->where('user_id', $freelancer->id)->where('type', 'submission_approved')->exists())->toBeTrue();
    expect(Notification::query()->where('user_id', $client->id)->where('type', 'task_completed')->exists())->toBeTrue();
});

it('returns approved assignment status and credited wallet balance to the freelancer app', function () {
    $client = User::factory()->create(['role' => 'client']);
    $freelancer = User::factory()->create(['role' => 'freelancer']);

    ClientProfile::query()->create(['user_id' => $client->id]);
    FreelancerProfile::query()->create(['user_id' => $freelancer->id]);

    $campaign = Campaign::query()->create([
        'client_id' => $client->id,
        'title' => 'Approved payout sync test',
        'task_type' => 'comment',
        'target_url' => 'https://youtube.com/watch?v=approved-sync',
        'target_quantity' => 10,
        'status' => 'active',
    ]);

    CampaignPricing::query()->create([
        'campaign_id' => $campaign->id,
        'client_unit_price' => 200,
        'freelancer_unit_payout' => 125,
        'platform_margin' => 75,
        'currency' => 'NGN',
    ]);

    Wallet::query()->create([
        'user_id' => $client->id,
        'wallet_type' => 'client_main',
        'currency' => 'NGN',
        'current_balance' => 5000,
        'pending_balance' => 0,
        'withdrawable_balance' => 0,
    ]);

    Wallet::query()->create([
        'user_id' => $freelancer->id,
        'wallet_type' => 'freelancer_main',
        'currency' => 'NGN',
        'current_balance' => 125,
        'pending_balance' => 0,
        'withdrawable_balance' => 125,
    ]);

    $assignment = TaskAssignment::query()->create([
        'campaign_id' => $campaign->id,
        'freelancer_id' => $freelancer->id,
        'assignment_code' => 'TASK-2001',
        'status' => 'approved',
        'assigned_at' => now(),
        'submitted_at' => now()->subMinute(),
        'approved_at' => now(),
    ]);

    TaskSubmission::query()->create([
        'assignment_id' => $assignment->id,
        'freelancer_id' => $freelancer->id,
        'client_id' => $client->id,
        'status' => 'approved',
        'submitted_at' => now()->subMinute(),
        'admin_decision' => 'approved',
        'admin_decision_at' => now(),
    ]);

    Sanctum::actingAs($freelancer);

    $this->getJson("/api/freelancer/tasks/{$assignment->id}")
        ->assertOk()
        ->assertJsonPath('assignment.status', 'approved');

    $this->getJson('/api/freelancer/wallet')
        ->assertOk()
        ->assertJsonPath('wallets.0.wallet_type', 'freelancer_main')
        ->assertJsonPath('wallets.0.current_balance', '125.00')
        ->assertJsonPath('wallets.0.withdrawable_balance', '125.00');
});

it('blocks freelancer account deletion while review-sensitive work is still pending', function () {
    $freelancer = User::factory()->create([
        'role' => 'freelancer',
        'email' => 'busy-freelancer@example.com',
    ]);

    FreelancerProfile::query()->create(['user_id' => $freelancer->id]);

    $client = User::factory()->create(['role' => 'client']);
    ClientProfile::query()->create(['user_id' => $client->id]);

    $campaign = Campaign::query()->create([
        'client_id' => $client->id,
        'title' => 'Pending freelancer deletion blocker',
        'task_type' => 'like',
        'target_url' => 'https://example.com/task',
        'target_quantity' => 1,
        'status' => 'active',
    ]);

    TaskAssignment::query()->create([
        'campaign_id' => $campaign->id,
        'freelancer_id' => $freelancer->id,
        'assignment_code' => 'BLOCK-DEL-1',
        'status' => 'in_progress',
        'assigned_at' => now(),
        'started_at' => now(),
    ]);

    Sanctum::actingAs($freelancer);

    $this->deleteJson('/api/auth/account')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account']);
});

it('deletes a freelancer account safely and frees the original email for reuse', function () {
    $freelancer = User::factory()->create([
        'role' => 'freelancer',
        'email' => 'delete-freelancer@example.com',
        'phone' => '08012345678',
    ]);

    FreelancerProfile::query()->create([
        'user_id' => $freelancer->id,
        'phone' => '08012345678',
        'bank_name' => 'Wema Bank',
        'account_name' => 'Delete Me',
        'account_number' => '0123456789',
    ]);

    Wallet::query()->create([
        'user_id' => $freelancer->id,
        'wallet_type' => 'freelancer_main',
        'currency' => 'NGN',
        'current_balance' => 0,
        'pending_balance' => 0,
        'withdrawable_balance' => 0,
    ]);

    Sanctum::actingAs($freelancer);

    $this->deleteJson('/api/auth/account')
        ->assertOk()
        ->assertJsonPath('message', 'Your account has been deleted successfully.');

    $deletedUser = User::withTrashed()->findOrFail($freelancer->id);

    expect($deletedUser->deleted_at)->not->toBeNull();
    expect($deletedUser->status)->toBe('deleted');
    expect($deletedUser->email)->not->toBe('delete-freelancer@example.com');
    expect($deletedUser->email)->toContain('deleted-user-'.$freelancer->id);
    expect($deletedUser->freelancerProfile?->bank_name)->toBeNull();
    expect($deletedUser->freelancerProfile?->account_name)->toBeNull();
    expect($deletedUser->freelancerProfile?->account_number)->toBeNull();

    $this->postJson('/api/auth/register', [
        'name' => 'Return Freelancer',
        'email' => 'delete-freelancer@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'freelancer',
    ])->assertCreated();
});

it('returns active task types from the database on the freelancer dashboard', function () {
    $freelancer = User::factory()->create(['role' => 'freelancer']);
    FreelancerProfile::query()->create(['user_id' => $freelancer->id]);

    TaskTypePricing::query()->create([
        'task_type' => 'comment',
        'client_unit_price' => 150,
        'freelancer_unit_payout' => 90,
        'currency' => 'NGN',
        'active' => true,
    ]);

    TaskTypePricing::query()->create([
        'task_type' => 'watch',
        'client_unit_price' => 140,
        'freelancer_unit_payout' => 85,
        'currency' => 'NGN',
        'active' => true,
    ]);

    Sanctum::actingAs($freelancer);

    $this->getJson('/api/freelancer/dashboard')
        ->assertOk()
        ->assertJsonPath('task_types.0', 'comment')
        ->assertJsonPath('task_types.1', 'watch');
});

it('rejects withdrawals larger than the freelancer withdrawable balance', function () {
    $freelancer = User::factory()->create(['role' => 'freelancer']);
    FreelancerProfile::query()->create(['user_id' => $freelancer->id]);

    Wallet::query()->create([
        'user_id' => $freelancer->id,
        'wallet_type' => 'freelancer_main',
        'currency' => 'NGN',
        'current_balance' => 1200,
        'pending_balance' => 0,
        'withdrawable_balance' => 1200,
    ]);

    Sanctum::actingAs($freelancer);

    $this->postJson('/api/freelancer/withdrawals', [
        'amount' => 1500,
        'destination_type' => 'bank',
        'destination_details' => [
            'bank_name' => 'GTBank',
            'account_number' => '0123456789',
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors(['amount']);

    $this->postJson('/api/freelancer/withdrawals', [
        'amount' => 1000,
        'destination_type' => 'bank',
        'destination_details' => [
            'bank_name' => 'GTBank',
            'account_number' => '0123456789',
        ],
    ])->assertCreated()->assertJsonPath('withdrawal.status', 'requested');
});

it('updates freelancer banking details', function () {
    $freelancer = User::factory()->create(['role' => 'freelancer']);
    FreelancerProfile::query()->create(['user_id' => $freelancer->id]);

    Sanctum::actingAs($freelancer);

    $this->patchJson('/api/freelancer/profile/banking', [
        'bank_name' => 'Access Bank',
        'account_name' => 'Ada Worker',
        'account_number' => '0123456789',
    ])->assertOk()->assertJsonPath('freelancer_profile.bank_name', 'Access Bank');

    expect($freelancer->fresh()->freelancerProfile?->bank_name)->toBe('Access Bank');
    expect($freelancer->fresh()->freelancerProfile?->account_name)->toBe('Ada Worker');
    expect($freelancer->fresh()->freelancerProfile?->account_number)->toBe('0123456789');
});
