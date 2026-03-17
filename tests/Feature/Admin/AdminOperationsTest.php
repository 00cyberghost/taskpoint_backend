<?php

use App\Models\Campaign;
use App\Models\CampaignPricing;
use App\Models\ClientFundingRequest;
use App\Models\ClientProfile;
use App\Models\FreelancerProfile;
use App\Models\Notification;
use App\Models\TaskAssignment;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

it('prevents approving the same submission twice', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $client = User::factory()->create(['role' => 'client']);
    $freelancer = User::factory()->create(['role' => 'freelancer']);

    ClientProfile::query()->create(['user_id' => $client->id]);
    FreelancerProfile::query()->create(['user_id' => $freelancer->id]);

    $campaign = Campaign::query()->create([
        'client_id' => $client->id,
        'title' => 'Double Approval Test',
        'task_type' => 'comment',
        'target_url' => 'https://youtube.com/watch?v=double-approve',
        'target_quantity' => 5,
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
        'current_balance' => 3000,
        'pending_balance' => 0,
        'withdrawable_balance' => 0,
    ]);

    $assignment = TaskAssignment::query()->create([
        'campaign_id' => $campaign->id,
        'freelancer_id' => $freelancer->id,
        'assignment_code' => 'TASK-REVIEW-1',
        'status' => 'submitted',
        'assigned_at' => now(),
        'submitted_at' => now(),
    ]);

    $submission = TaskSubmission::query()->create([
        'assignment_id' => $assignment->id,
        'freelancer_id' => $freelancer->id,
        'client_id' => $client->id,
        'status' => 'client_review',
        'submitted_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post("/admin/reviews/{$submission->id}/approve")
        ->assertRedirect();

    $this->actingAs($admin)
        ->post("/admin/reviews/{$submission->id}/approve")
        ->assertSessionHasErrors('submission');

    expect(WalletTransaction::query()->where('reference_type', TaskSubmission::class)->where('reference_id', $submission->id)->count())
        ->toBe(3);
    expect($campaign->fresh()->completed_quantity)->toBe(1);
});

it('settles wallet balances when a withdrawal is marked paid', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $freelancer = User::factory()->create(['role' => 'freelancer']);

    FreelancerProfile::query()->create(['user_id' => $freelancer->id]);

    $wallet = Wallet::query()->create([
        'user_id' => $freelancer->id,
        'wallet_type' => 'freelancer_main',
        'currency' => 'NGN',
        'current_balance' => 2500,
        'pending_balance' => 0,
        'withdrawable_balance' => 2500,
    ]);

    $withdrawal = WithdrawalRequest::query()->create([
        'freelancer_id' => $freelancer->id,
        'amount' => 1000,
        'destination_type' => 'bank',
        'destination_details' => ['bank_name' => 'GTBank'],
        'status' => 'approved',
        'requested_at' => now(),
    ]);

    $this->actingAs($admin)
        ->patch("/admin/finance/withdrawals/{$withdrawal->id}", ['status' => 'paid'])
        ->assertRedirect();

    expect($wallet->fresh()->current_balance)->toBe('1500.00');
    expect($wallet->fresh()->withdrawable_balance)->toBe('1500.00');
    expect(WalletTransaction::query()->where('transaction_type', 'withdrawal_payout')->where('reference_id', $withdrawal->id)->exists())
        ->toBeTrue();
    expect(Notification::query()->where('user_id', $freelancer->id)->where('type', 'withdrawal_paid')->exists())
        ->toBeTrue();
});

it('renders the admin notifications page', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $freelancer = User::factory()->create(['role' => 'freelancer']);

    Notification::query()->create([
        'user_id' => $freelancer->id,
        'type' => 'task_completed',
        'title' => 'Task completed',
        'body' => 'A campaign unit was approved.',
        'channel' => 'push',
        'delivery_status' => 'sent',
    ]);

    $this->actingAs($admin)
        ->get('/admin/notifications')
        ->assertOk();
});

it('credits client wallet when a funding request is approved', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $client = User::factory()->create(['role' => 'client']);

    ClientProfile::query()->create(['user_id' => $client->id]);

    $fundingRequest = ClientFundingRequest::query()->create([
        'client_id' => $client->id,
        'amount' => 5000,
        'payment_method' => 'manual',
        'status' => 'pending',
        'submitted_at' => now(),
    ]);

    $this->actingAs($admin)
        ->patch("/admin/finance/funding-requests/{$fundingRequest->id}", ['status' => 'approved'])
        ->assertRedirect();

    $wallet = Wallet::query()->where('user_id', $client->id)->where('wallet_type', 'client_main')->firstOrFail();

    expect($wallet->current_balance)->toBe('5000.00');
    expect(WalletTransaction::query()->where('transaction_type', 'client_wallet_funding')->where('reference_id', $fundingRequest->id)->exists())
        ->toBeTrue();
    expect(Notification::query()->where('user_id', $client->id)->where('type', 'wallet_funding_approved')->exists())
        ->toBeTrue();
});

it('marks a campaign as completed once approved submissions reach the target quantity', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $client = User::factory()->create(['role' => 'client']);
    $freelancer = User::factory()->create(['role' => 'freelancer']);

    ClientProfile::query()->create(['user_id' => $client->id]);
    FreelancerProfile::query()->create(['user_id' => $freelancer->id]);

    $campaign = Campaign::query()->create([
        'client_id' => $client->id,
        'title' => 'Completion threshold test',
        'task_type' => 'like',
        'target_url' => 'https://youtube.com/watch?v=complete-me',
        'target_quantity' => 1,
        'completed_quantity' => 0,
        'status' => 'active',
    ]);

    CampaignPricing::query()->create([
        'campaign_id' => $campaign->id,
        'client_unit_price' => 150,
        'freelancer_unit_payout' => 90,
        'platform_margin' => 60,
        'currency' => 'NGN',
    ]);

    Wallet::query()->create([
        'user_id' => $client->id,
        'wallet_type' => 'client_main',
        'currency' => 'NGN',
        'current_balance' => 1000,
        'pending_balance' => 0,
        'withdrawable_balance' => 0,
    ]);

    $assignment = TaskAssignment::query()->create([
        'campaign_id' => $campaign->id,
        'freelancer_id' => $freelancer->id,
        'assignment_code' => 'TASK-COMPLETE-1',
        'status' => 'submitted',
        'assigned_at' => now(),
        'submitted_at' => now(),
    ]);

    $submission = TaskSubmission::query()->create([
        'assignment_id' => $assignment->id,
        'freelancer_id' => $freelancer->id,
        'client_id' => $client->id,
        'status' => 'client_review',
        'submitted_at' => now(),
    ]);

    $this->actingAs($admin)
        ->post("/admin/reviews/{$submission->id}/approve")
        ->assertRedirect();

    expect($campaign->fresh()->completed_quantity)->toBe(1);
    expect($campaign->fresh()->status)->toBe('completed');
    expect(Notification::query()->where('user_id', $client->id)->where('type', 'campaign_completed')->exists())
        ->toBeTrue();
});

it('allows admin to send notifications to freelancers and individual users', function () {
    $admin = User::factory()->create(['role' => 'admin']);
    $freelancer = User::factory()->create(['role' => 'freelancer', 'status' => 'active']);
    $client = User::factory()->create(['role' => 'client', 'status' => 'active']);

    $this->actingAs($admin)
        ->post('/admin/notifications/send', [
            'audience' => 'freelancers',
            'title' => 'Freelancer update',
            'body' => 'A new platform-wide update is available for workers.',
        ])
        ->assertRedirect();

    expect(Notification::query()->where('user_id', $freelancer->id)->where('type', 'admin_broadcast')->exists())
        ->toBeTrue();
    expect(Notification::query()->where('user_id', $client->id)->where('type', 'admin_broadcast')->exists())
        ->toBeFalse();

    $this->actingAs($admin)
        ->post('/admin/notifications/send', [
            'audience' => 'individual',
            'user_id' => $client->id,
            'title' => 'Client update',
            'body' => 'Your campaign has a special note from the admin team.',
        ])
        ->assertRedirect();

    expect(Notification::query()->where('user_id', $client->id)->where('title', 'Client update')->exists())
        ->toBeTrue();
});

it('allows admin to send notifications with an optional image attachment', function () {
    Storage::fake('public');

    $admin = User::factory()->create(['role' => 'admin']);
    $freelancer = User::factory()->create(['role' => 'freelancer', 'status' => 'active']);

    $this->actingAs($admin)
        ->post('/admin/notifications/send', [
            'audience' => 'individual',
            'user_id' => $freelancer->id,
            'title' => 'Image update',
            'body' => 'This notification includes an image.',
            'image' => UploadedFile::fake()->image('notice.png'),
        ])
        ->assertRedirect();

    $notification = Notification::query()
        ->where('user_id', $freelancer->id)
        ->where('title', 'Image update')
        ->latest()
        ->firstOrFail();

    expect($notification->image_path)->not->toBeNull();
    Storage::disk('public')->assertExists($notification->image_path);
});
