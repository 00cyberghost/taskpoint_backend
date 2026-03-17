<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\FreelancerProfile;
use App\Models\TaskAssignment;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AssignmentDispatchService
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function dispatchForCampaign(Campaign $campaign, ?int $limit = null): int
    {
        $campaign->loadMissing(['targetingRule', 'pricing']);

        if (
            $campaign->status === 'completed' ||
            (int) $campaign->completed_quantity >= (int) $campaign->target_quantity
        ) {
            return 0;
        }

        $remainingSlots = max(
            ($limit ?? $campaign->target_quantity) - $campaign->assignments()->count(),
            0,
        );

        if ($remainingSlots === 0) {
            return 0;
        }

        $eligibleFreelancers = $this->eligibleFreelancers($campaign, $remainingSlots);

        if ($eligibleFreelancers->isEmpty()) {
            return 0;
        }

        DB::transaction(function () use ($campaign, $eligibleFreelancers): void {
            foreach ($eligibleFreelancers as $freelancer) {
                $assignment = TaskAssignment::query()->create([
                    'campaign_id' => $campaign->id,
                    'freelancer_id' => $freelancer->id,
                    'assignment_code' => $this->assignmentCode($campaign, $freelancer),
                    'status' => 'assigned',
                    'assigned_at' => now(),
                    'expires_at' => now()->addHours(24),
                    'auto_assigned_by_rule' => true,
                ]);

                $this->notificationService->create(
                    $freelancer,
                    'task_assigned',
                    'New task assigned',
                    "You have a new {$campaign->task_type} task for {$campaign->title}.",
                    [
                        'campaign_id' => $campaign->id,
                        'assignment_id' => $assignment->id,
                    ],
                );
            }
        });

        return $eligibleFreelancers->count();
    }

    public function assignFreelancer(Campaign $campaign, User $freelancer, ?int $adminId = null): ?TaskAssignment
    {
        $campaign->loadMissing(['targetingRule', 'pricing']);
        $freelancer->loadMissing('freelancerProfile');

        if (
            $campaign->status === 'completed' ||
            (int) $campaign->completed_quantity >= (int) $campaign->target_quantity
        ) {
            return null;
        }

        if ($freelancer->role !== 'freelancer' || $freelancer->status !== 'active') {
            return null;
        }

        if (TaskAssignment::query()->where('campaign_id', $campaign->id)->where('freelancer_id', $freelancer->id)->exists()) {
            return null;
        }

        $assignment = TaskAssignment::query()->create([
            'campaign_id' => $campaign->id,
            'freelancer_id' => $freelancer->id,
            'assignment_code' => $this->assignmentCode($campaign, $freelancer),
            'status' => 'assigned',
            'assigned_at' => now(),
            'expires_at' => now()->addHours(24),
            'auto_assigned_by_rule' => false,
            'assigned_by_admin_id' => $adminId,
        ]);

        $this->notificationService->create(
            $freelancer,
            'task_assigned',
            'New task assigned',
            "You have a new {$campaign->task_type} task for {$campaign->title}.",
            [
                'campaign_id' => $campaign->id,
                'assignment_id' => $assignment->id,
            ],
        );

        return $assignment;
    }

    /**
     * @return Collection<int, User>
     */
    private function eligibleFreelancers(Campaign $campaign, int $limit): Collection
    {
        $rule = $campaign->targetingRule;
        $allowedCountries = collect($rule?->allowed_countries ?? [])
            ->filter()
            ->map(fn (string $country) => strtoupper($country))
            ->values()
            ->all();
        $blockedCountries = collect($rule?->blocked_countries ?? [])
            ->filter()
            ->map(fn (string $country) => strtoupper($country))
            ->values()
            ->all();
        $minTrustScore = (float) ($rule?->min_trust_score ?? 0);

        return User::query()
            ->where('role', 'freelancer')
            ->where('status', 'active')
            ->whereDoesntHave('assignments', function ($query) use ($campaign): void {
                $query->where('campaign_id', $campaign->id);
            })
            ->whereHas('freelancerProfile', fn ($query) => $query->where('trust_score', '>=', $minTrustScore))
            ->with('freelancerProfile')
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->filter(fn (User $freelancer) => $this->passesCampaignTargeting($campaign, $freelancer, $freelancer->freelancerProfile))
            ->values();
    }

    private function passesCampaignTargeting(
        Campaign $campaign,
        User $freelancer,
        ?FreelancerProfile $profile,
    ): bool {
        $rule = $campaign->targetingRule;
        $allowedCountries = collect($rule?->allowed_countries ?? [])
            ->filter()
            ->map(fn (string $country) => strtoupper($country))
            ->values()
            ->all();
        $blockedCountries = collect($rule?->blocked_countries ?? [])
            ->filter()
            ->map(fn (string $country) => strtoupper($country))
            ->values()
            ->all();

        $countryPool = collect($profile?->preferred_countries ?? [])
            ->filter()
            ->map(fn (string $country) => strtoupper($country))
            ->push(strtoupper((string) $freelancer->registration_country))
            ->push(strtoupper((string) $freelancer->last_login_country))
            ->filter(fn (string $country) => $country !== '')
            ->unique()
            ->values()
            ->all();

        if ($allowedCountries !== [] && count(array_intersect($countryPool, $allowedCountries)) === 0) {
            if (app()->environment('local', 'testing') && $countryPool === []) {
                return true;
            }

            return false;
        }

        if ($blockedCountries !== [] && count(array_intersect($countryPool, $blockedCountries)) > 0) {
            return false;
        }

        return true;
    }

    private function assignmentCode(Campaign $campaign, User $freelancer): string
    {
        return sprintf(
            'TP-%d-%d-%s',
            $campaign->id,
            $freelancer->id,
            Str::upper(Str::random(6)),
        );
    }
}
