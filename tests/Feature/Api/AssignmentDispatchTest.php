<?php

use App\Models\Campaign;
use App\Models\CampaignTargetingRule;
use App\Models\FreelancerProfile;
use App\Models\Notification;
use App\Models\TaskAssignment;
use App\Models\User;
use App\Services\AssignmentDispatchService;

it('dispatches active campaigns to eligible freelancers', function () {
    $client = User::factory()->create(['role' => 'client']);

    $campaign = Campaign::query()->create([
        'client_id' => $client->id,
        'title' => 'Nigeria follow drive',
        'task_type' => 'follow',
        'target_url' => 'https://instagram.com/taskpoint',
        'target_quantity' => 2,
        'completed_quantity' => 0,
        'status' => 'active',
        'review_mode' => 'hybrid',
        'proof_mode' => 'auto_and_manual',
    ]);

    CampaignTargetingRule::query()->create([
        'campaign_id' => $campaign->id,
        'allowed_countries' => ['NG'],
        'min_trust_score' => 10,
    ]);

    $eligibleOne = User::factory()->create(['role' => 'freelancer', 'status' => 'active']);
    FreelancerProfile::query()->create([
        'user_id' => $eligibleOne->id,
        'trust_score' => 25,
        'preferred_countries' => ['NG'],
    ]);

    $eligibleTwo = User::factory()->create(['role' => 'freelancer', 'status' => 'active']);
    FreelancerProfile::query()->create([
        'user_id' => $eligibleTwo->id,
        'trust_score' => 15,
        'preferred_countries' => ['NG', 'GH'],
    ]);

    $ineligible = User::factory()->create(['role' => 'freelancer', 'status' => 'active']);
    FreelancerProfile::query()->create([
        'user_id' => $ineligible->id,
        'trust_score' => 2,
        'preferred_countries' => ['NG'],
    ]);

    $count = app(AssignmentDispatchService::class)->dispatchForCampaign($campaign);

    expect($count)->toBe(2);
    expect(TaskAssignment::query()->where('campaign_id', $campaign->id)->count())->toBe(2);
    expect(TaskAssignment::query()->where('campaign_id', $campaign->id)->where('freelancer_id', $eligibleOne->id)->exists())->toBeTrue();
    expect(TaskAssignment::query()->where('campaign_id', $campaign->id)->where('freelancer_id', $eligibleTwo->id)->exists())->toBeTrue();
    expect(TaskAssignment::query()->where('campaign_id', $campaign->id)->where('freelancer_id', $ineligible->id)->exists())->toBeFalse();
    expect(Notification::query()->where('user_id', $eligibleOne->id)->where('type', 'task_assigned')->exists())->toBeTrue();
});

it('falls back to registration country when preferred countries are missing', function () {
    $client = User::factory()->create(['role' => 'client']);

    $campaign = Campaign::query()->create([
        'client_id' => $client->id,
        'title' => 'Local country targeted campaign',
        'task_type' => 'follow',
        'target_url' => 'https://instagram.com/taskpoint',
        'target_quantity' => 1,
        'completed_quantity' => 0,
        'status' => 'active',
        'review_mode' => 'hybrid',
        'proof_mode' => 'auto_and_manual',
    ]);

    CampaignTargetingRule::query()->create([
        'campaign_id' => $campaign->id,
        'allowed_countries' => ['NG'],
        'min_trust_score' => 0,
    ]);

    $freelancer = User::factory()->create([
        'role' => 'freelancer',
        'status' => 'active',
        'registration_country' => 'NG',
    ]);
    FreelancerProfile::query()->create([
        'user_id' => $freelancer->id,
        'trust_score' => 5,
        'preferred_countries' => null,
    ]);

    $count = app(AssignmentDispatchService::class)->dispatchForCampaign($campaign);

    expect($count)->toBe(1);
    expect(TaskAssignment::query()->where('campaign_id', $campaign->id)->where('freelancer_id', $freelancer->id)->exists())->toBeTrue();
});
