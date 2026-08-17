<?php

use App\Models\Campaign;
use App\Models\ClientFundingRequest;
use App\Models\ClientProfile;
use App\Models\EmailVerificationOtp;
use App\Models\PlatformPaymentSetting;
use App\Models\TaskAssignment;
use App\Models\TaskTypePricing;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Models\Wallet;
use App\Services\GoogleIdentityService;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use App\Mail\EmailVerificationOtpMail;

it('registers a client and creates the client profile', function () {
    Mail::fake();

    $response = $this
        ->withHeaders(['CF-IPCountry' => 'NG'])
        ->postJson('/api/auth/register', [
            'name' => 'Acme Client',
            'email' => 'client@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'role' => 'client',
        ]);

    $response
        ->assertCreated()
        ->assertJsonPath('user.role', 'client');

    $user = User::query()->where('email', 'client@example.com')->firstOrFail();

    expect(ClientProfile::query()->where('user_id', $user->id)->exists())->toBeTrue();
    expect($user->registration_country)->toBe('NG');
    expect(EmailVerificationOtp::query()->where('user_id', $user->id)->exists())->toBeTrue();
    Mail::assertSent(EmailVerificationOtpMail::class);
});

it('blocks unverified clients from protected platform routes', function () {
    $client = User::factory()->unverified()->create([
        'role' => 'client',
        'status' => 'active',
    ]);

    ClientProfile::query()->create(['user_id' => $client->id]);

    Sanctum::actingAs($client);

    $this->getJson('/api/client/dashboard')
        ->assertForbidden()
        ->assertJsonPath('message', 'Please verify your email address before using the platform.');
});

it('verifies a client email with otp', function () {
    $client = User::factory()->unverified()->create([
        'role' => 'client',
        'status' => 'active',
    ]);

    ClientProfile::query()->create(['user_id' => $client->id]);

    EmailVerificationOtp::query()->create([
        'user_id' => $client->id,
        'code_hash' => bcrypt('654321'),
        'attempts' => 0,
        'expires_at' => now()->addMinutes(10),
        'sent_at' => now(),
    ]);

    Sanctum::actingAs($client);

    $this->postJson('/api/auth/email/verify-otp', [
        'otp' => '654321',
    ])->assertOk()->assertJsonPath('message', 'Email verified successfully.');

    expect($client->fresh()->hasVerifiedEmail())->toBeTrue();
    expect(EmailVerificationOtp::query()->where('user_id', $client->id)->exists())->toBeFalse();
});

it('logs a client in with google and reuses an existing email-matched account', function () {
    $client = User::factory()->unverified()->create([
        'role' => 'client',
        'email' => 'google.client@example.com',
    ]);

    ClientProfile::query()->create(['user_id' => $client->id]);

    app()->instance(GoogleIdentityService::class, new class extends GoogleIdentityService
    {
        public function verify(string $idToken): array
        {
            expect($idToken)->toBe('client-google-token');

            return [
                'sub' => 'google-client-456',
                'email' => 'google.client@example.com',
                'email_verified' => true,
                'name' => 'Google Client',
            ];
        }
    });

    $response = $this->postJson('/api/auth/google', [
        'id_token' => 'client-google-token',
        'role' => 'client',
        'device_name' => 'ios-test',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('user.role', 'client')
        ->assertJsonPath('user.email', 'google.client@example.com');

    expect($client->fresh()->google_id)->toBe('google-client-456');
    expect($client->fresh()->hasVerifiedEmail())->toBeTrue();
});

it('lets a client create campaigns and fetch dashboard data', function () {
    $client = User::factory()->create(['role' => 'client']);
    ClientProfile::query()->create(['user_id' => $client->id]);

    Wallet::query()->create([
        'user_id' => $client->id,
        'wallet_type' => 'client_main',
        'currency' => 'NGN',
        'current_balance' => 20000,
        'pending_balance' => 0,
        'withdrawable_balance' => 0,
    ]);

    Sanctum::actingAs($client);

    $this->postJson('/api/client/campaigns', [
        'title' => 'YouTube Comment Campaign',
        'description' => 'Ask freelancers to post relevant comments.',
        'task_type' => 'comment',
        'target_url' => 'https://youtube.com/watch?v=test123',
        'target_quantity' => 100,
        'client_unit_price' => 150,
        'allowed_countries' => ['NG'],
    ])->assertCreated()->assertJsonPath('campaign.status', 'submitted');

    $dashboard = $this->getJson('/api/client/dashboard');

    $dashboard
        ->assertOk()
        ->assertJsonPath('summary.active_campaigns', 0)
        ->assertJsonPath('summary.total_reach', 100)
        ->assertJsonCount(1, 'campaigns');

    expect(Campaign::query()->where('client_id', $client->id)->exists())->toBeTrue();
});

it('blocks campaign creation when client wallet funds are insufficient', function () {
    $client = User::factory()->create(['role' => 'client']);
    ClientProfile::query()->create(['user_id' => $client->id]);

    Wallet::query()->create([
        'user_id' => $client->id,
        'wallet_type' => 'client_main',
        'currency' => 'NGN',
        'current_balance' => 1000,
        'pending_balance' => 0,
        'withdrawable_balance' => 0,
    ]);

    Sanctum::actingAs($client);

    $this->postJson('/api/client/campaigns', [
        'title' => 'Large campaign',
        'task_type' => 'comment',
        'target_url' => 'https://youtube.com/watch?v=test123',
        'target_quantity' => 100,
        'client_unit_price' => 150,
    ])->assertUnprocessable()->assertJsonValidationErrors(['balance']);
});

it('uses admin task type pricing defaults for new campaigns', function () {
    $client = User::factory()->create(['role' => 'client']);
    ClientProfile::query()->create(['user_id' => $client->id]);

    Wallet::query()->create([
        'user_id' => $client->id,
        'wallet_type' => 'client_main',
        'currency' => 'NGN',
        'current_balance' => 20000,
        'pending_balance' => 0,
        'withdrawable_balance' => 0,
    ]);

    TaskTypePricing::query()->create([
        'task_type' => 'follow',
        'client_unit_price' => 180,
        'freelancer_unit_payout' => 95,
        'currency' => 'NGN',
        'active' => true,
    ]);

    Sanctum::actingAs($client);

    $response = $this->postJson('/api/client/campaigns', [
        'title' => 'Follower campaign',
        'task_type' => 'follow',
        'target_url' => 'https://instagram.com/taskpoint',
        'target_quantity' => 10,
    ])->assertCreated();

    $campaignId = $response->json('campaign.id');

    expect(Campaign::query()->findOrFail($campaignId)->pricing?->client_unit_price)->toBe('180.00');
    expect(Campaign::query()->findOrFail($campaignId)->pricing?->freelancer_unit_payout)->toBe('95.00');
});

it('returns funding config and accepts manual funding requests', function () {
    $client = User::factory()->create(['role' => 'client']);
    ClientProfile::query()->create(['user_id' => $client->id]);

    PlatformPaymentSetting::query()->create([
        'active_method' => 'manual',
        'manual_bank_name' => 'Wema Bank',
        'manual_account_name' => 'TaskPoint Technologies',
        'manual_account_number' => '0123456789',
    ]);

    Sanctum::actingAs($client);

    $this->getJson('/api/client/wallet')
        ->assertOk()
        ->assertJsonPath('funding_config.manual_bank_name', 'Wema Bank');

    $this->postJson('/api/client/funding-requests', [
        'amount' => 5000,
        'payment_method' => 'manual',
    ])->assertCreated()->assertJsonPath('funding_request.status', 'pending');

    expect(ClientFundingRequest::query()->where('client_id', $client->id)->exists())->toBeTrue();
});

it('returns updated wallet balances after a funding request is approved', function () {
    $client = User::factory()->create(['role' => 'client']);
    ClientProfile::query()->create(['user_id' => $client->id]);

    Wallet::query()->create([
        'user_id' => $client->id,
        'wallet_type' => 'client_main',
        'currency' => 'NGN',
        'current_balance' => 0,
        'pending_balance' => 0,
        'withdrawable_balance' => 0,
    ]);

    ClientFundingRequest::query()->create([
        'client_id' => $client->id,
        'amount' => 7500,
        'payment_method' => 'manual',
        'status' => 'approved',
        'submitted_at' => now(),
        'reviewed_at' => now(),
    ]);

    Wallet::query()
        ->where('user_id', $client->id)
        ->where('wallet_type', 'client_main')
        ->update([
            'current_balance' => 7500,
            'withdrawable_balance' => 7500,
        ]);

    Sanctum::actingAs($client);

    $this->getJson('/api/client/wallet')
        ->assertOk()
        ->assertJsonPath('wallets.0.current_balance', '7500.00')
        ->assertJsonPath('funding_requests.0.status', 'approved');

    $this->getJson('/api/client/dashboard')
        ->assertOk()
        ->assertJsonPath('wallet.current_balance', '7500.00')
        ->assertJsonPath('summary.available_balance', 7500);
});

it('lists client review queue submissions', function () {
    $client = User::factory()->create(['role' => 'client']);
    $freelancer = User::factory()->create(['role' => 'freelancer']);
    ClientProfile::query()->create(['user_id' => $client->id]);

    $campaign = Campaign::query()->create([
        'client_id' => $client->id,
        'title' => 'Instagram follow campaign',
        'task_type' => 'follow',
        'target_url' => 'https://instagram.com/test',
        'target_quantity' => 50,
        'completed_quantity' => 0,
        'status' => 'active',
        'review_mode' => 'hybrid',
        'proof_mode' => 'auto_and_manual',
    ]);

    $assignment = TaskAssignment::query()->create([
        'campaign_id' => $campaign->id,
        'freelancer_id' => $freelancer->id,
        'assignment_code' => 'CLI-100',
        'status' => 'submitted',
        'assigned_at' => now(),
        'submitted_at' => now(),
    ]);

    TaskSubmission::query()->create([
        'assignment_id' => $assignment->id,
        'freelancer_id' => $freelancer->id,
        'client_id' => $client->id,
        'status' => 'client_review',
        'submitted_at' => now(),
    ]);

    Sanctum::actingAs($client);

    $this->getJson('/api/client/reviews?status=client_review')
        ->assertOk()
        ->assertJsonCount(1, 'submissions.data');
});

it('blocks client account deletion while active campaigns still exist', function () {
    $client = User::factory()->create([
        'role' => 'client',
        'email' => 'active-client@example.com',
    ]);

    ClientProfile::query()->create(['user_id' => $client->id]);

    Campaign::query()->create([
        'client_id' => $client->id,
        'title' => 'Still active',
        'task_type' => 'follow',
        'target_url' => 'https://example.com/active',
        'target_quantity' => 10,
        'status' => 'active',
    ]);

    Sanctum::actingAs($client);

    $this->deleteJson('/api/auth/account')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['account']);
});

it('deletes a client account safely and frees the original email for reuse', function () {
    $client = User::factory()->create([
        'role' => 'client',
        'email' => 'delete-client@example.com',
        'phone' => '08087654321',
    ]);

    ClientProfile::query()->create([
        'user_id' => $client->id,
        'company_name' => 'Delete Client Ltd',
        'phone' => '08087654321',
        'default_country_target' => 'NG',
    ]);

    Wallet::query()->create([
        'user_id' => $client->id,
        'wallet_type' => 'client_main',
        'currency' => 'NGN',
        'current_balance' => 0,
        'pending_balance' => 0,
        'withdrawable_balance' => 0,
    ]);

    Sanctum::actingAs($client);

    $this->deleteJson('/api/auth/account')
        ->assertOk()
        ->assertJsonPath('message', 'Your account has been deleted successfully.');

    $deletedUser = User::withTrashed()->findOrFail($client->id);

    expect($deletedUser->deleted_at)->not->toBeNull();
    expect($deletedUser->status)->toBe('deleted');
    expect($deletedUser->email)->not->toBe('delete-client@example.com');
    expect($deletedUser->email)->toContain('deleted-user-'.$client->id);
    expect($deletedUser->clientProfile?->phone)->toBeNull();
    expect($deletedUser->clientProfile?->company_name)->toBe('Deleted Client');

    $this->postJson('/api/auth/register', [
        'name' => 'Returning Client',
        'email' => 'delete-client@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'client',
    ])->assertCreated();
});
