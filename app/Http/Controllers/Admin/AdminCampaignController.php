<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\TaskTypePricing;
use App\Models\User;
use App\Services\AssignmentDispatchService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminCampaignController extends Controller
{
    public function __construct(
        private readonly AssignmentDispatchService $assignmentDispatchService,
    ) {
    }

    public function index(): Response
    {
        collect([
            ['task_type' => 'like', 'client_unit_price' => 100, 'freelancer_unit_payout' => 60],
            ['task_type' => 'follow', 'client_unit_price' => 120, 'freelancer_unit_payout' => 70],
            ['task_type' => 'comment', 'client_unit_price' => 150, 'freelancer_unit_payout' => 90],
            ['task_type' => 'share', 'client_unit_price' => 130, 'freelancer_unit_payout' => 75],
            ['task_type' => 'watch', 'client_unit_price' => 140, 'freelancer_unit_payout' => 85],
        ])->each(function (array $pricing): void {
            TaskTypePricing::query()->firstOrCreate(
                ['task_type' => $pricing['task_type']],
                [
                    'client_unit_price' => $pricing['client_unit_price'],
                    'freelancer_unit_payout' => $pricing['freelancer_unit_payout'],
                    'currency' => 'NGN',
                    'active' => true,
                ],
            );
        });

        return Inertia::render('admin-campaigns', [
            'campaigns' => Campaign::query()
                ->with([
                    'client:id,name,email',
                    'pricing:id,campaign_id,client_unit_price,freelancer_unit_payout,platform_margin,currency',
                    'funds:id,campaign_id,total_funded,total_reserved,total_spent,total_refunded',
                    'targetingRule:id,campaign_id,allowed_countries,min_trust_score,max_assignments_per_freelancer',
                    'assignments.freelancer:id,name,email,registration_country',
                ])
                ->latest()
                ->paginate(12)
                ->withQueryString(),
            'freelancers' => User::query()
                ->with('freelancerProfile:id,user_id,verification_status,preferred_countries,trust_score')
                ->where('role', 'freelancer')
                ->where('status', 'active')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'registration_country']),
            'taskTypePricings' => TaskTypePricing::query()
                ->orderBy('task_type')
                ->get(),
        ]);
    }

    public function update(Request $request, Campaign $campaign): RedirectResponse
    {
        $previousStatus = $campaign->status;
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:50'],
            'review_mode' => ['nullable', 'string', 'max:50'],
            'proof_mode' => ['nullable', 'string', 'max:50'],
            'client_unit_price' => ['nullable', 'numeric', 'min:0'],
            'freelancer_unit_payout' => ['nullable', 'numeric', 'min:0'],
            'manual_freelancer_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $campaign->fill(array_filter([
            'status' => $validated['status'] ?? null,
            'review_mode' => $validated['review_mode'] ?? null,
            'proof_mode' => $validated['proof_mode'] ?? null,
        ], static fn ($value) => $value !== null))->save();

        if (array_key_exists('client_unit_price', $validated) || array_key_exists('freelancer_unit_payout', $validated)) {
            $pricing = $campaign->pricing()->firstOrNew();
            $clientUnitPrice = (float) ($validated['client_unit_price'] ?? $pricing->client_unit_price ?? 0);
            $freelancerUnitPayout = (float) ($validated['freelancer_unit_payout'] ?? $pricing->freelancer_unit_payout ?? 0);

            $pricing->fill([
                'client_unit_price' => $clientUnitPrice,
                'freelancer_unit_payout' => $freelancerUnitPayout,
                'platform_margin' => max($clientUnitPrice - $freelancerUnitPayout, 0),
                'currency' => $pricing->currency ?? 'NGN',
                'payout_minimum_snapshot' => $pricing->payout_minimum_snapshot ?? 1000,
            ])->save();
        }

        if (($validated['status'] ?? null) === 'active') {
            $dispatched = $this->assignmentDispatchService->dispatchForCampaign($campaign->fresh());

            return back()->with(
                'success',
                $dispatched > 0
                    ? "Campaign activated and {$dispatched} freelancer assignments were created."
                    : ($previousStatus === 'active'
                        ? 'Campaign remains active. No new eligible freelancers were available for assignment.'
                        : 'Campaign activated, but no eligible freelancers were available for assignment yet.'),
            );
        }

        if (isset($validated['manual_freelancer_id'])) {
            $freelancer = User::query()->where('role', 'freelancer')->findOrFail($validated['manual_freelancer_id']);
            $assignment = $this->assignmentDispatchService->assignFreelancer($campaign->fresh(), $freelancer, $request->user()?->id);

            return back()->with(
                $assignment
                    ? 'success'
                    : 'error',
                $assignment
                    ? "Assigned {$freelancer->name} to {$campaign->title}."
                    : "{$freelancer->name} could not be assigned to this campaign.",
            );
        }

        return back()->with('success', 'Campaign updated successfully.');
    }

    public function updateTaskTypePricing(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'task_type' => ['required', 'string', 'max:100'],
            'client_unit_price' => ['required', 'numeric', 'min:0'],
            'freelancer_unit_payout' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'active' => ['nullable', 'boolean'],
        ]);

        TaskTypePricing::query()->updateOrCreate(
            ['task_type' => strtolower($validated['task_type'])],
            [
                'client_unit_price' => $validated['client_unit_price'],
                'freelancer_unit_payout' => $validated['freelancer_unit_payout'],
                'currency' => $validated['currency'] ?? 'NGN',
                'active' => $validated['active'] ?? true,
            ],
        );

        return back()->with('success', 'Task type pricing updated successfully.');
    }
}
