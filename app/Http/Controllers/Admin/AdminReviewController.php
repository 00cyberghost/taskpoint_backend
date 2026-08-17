<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ReviewDecision;
use App\Models\TaskSubmission;
use App\Services\NotificationService;
use App\Services\WalletLedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminReviewController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly WalletLedgerService $walletLedgerService,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin-reviews', [
            'submissions' => TaskSubmission::query()
                ->with([
                    'assignment.campaign:id,title,target_url',
                    'assignment.campaign.pricing:id,campaign_id,client_unit_price,freelancer_unit_payout,platform_margin',
                    'client:id,name,email',
                    'freelancer:id,name,email',
                    'proofs:id,submission_id,type,file_path,source,created_at',
                    'reviewDecisions:id,submission_id,actor_id,actor_role,decision,note,created_at',
                ])
                ->latest('submitted_at')
                ->paginate(12)
                ->withQueryString(),
        ]);
    }

    public function approve(Request $request, TaskSubmission $submission): RedirectResponse
    {
        if ($submission->status === 'approved') {
            throw ValidationException::withMessages([
                'submission' => ['This submission has already been approved.'],
            ]);
        }

        if ($submission->status === 'rejected') {
            throw ValidationException::withMessages([
                'submission' => ['Rejected submissions cannot be approved without reopening them first.'],
            ]);
        }

        DB::transaction(function () use ($request, $submission): void {
            $submission->loadMissing([
                'assignment.campaign.pricing',
                'assignment.campaign.funds',
                'freelancer.freelancerProfile',
            ]);

            $submission->update([
                'status' => 'approved',
                'admin_decision' => 'approved',
                'admin_decision_at' => now(),
                'final_decision_by' => $request->user()?->id,
            ]);

            $submission->assignment?->update([
                'status' => 'approved',
                'approved_at' => now(),
            ]);

            ReviewDecision::query()->create([
                'submission_id' => $submission->id,
                'actor_id' => $request->user()?->id,
                'actor_role' => $request->user()?->role ?? 'admin',
                'decision' => 'approved',
                'note' => 'Approved from admin review queue.',
            ]);

            $pricing = $submission->assignment?->campaign?->pricing;
            $clientCharge = (float) ($pricing?->client_unit_price ?? 0);
            $freelancerPayout = (float) ($pricing?->freelancer_unit_payout ?? 0);
            $platformMargin = max($clientCharge - $freelancerPayout, 0);

            $freelancerWallet = $this->walletLedgerService->walletFor($submission->freelancer_id, 'freelancer_main');
            $clientWallet = $this->walletLedgerService->walletFor($submission->client_id, 'client_main');

            $this->walletLedgerService->credit($freelancerWallet, $freelancerPayout, [
                'transaction_type' => 'freelancer_payout',
                'reference_type' => TaskSubmission::class,
                'reference_id' => $submission->id,
                'status' => 'approved',
                'description' => 'Approved task completion payout.',
            ]);

            $this->walletLedgerService->debit($clientWallet, $clientCharge, [
                'transaction_type' => 'client_campaign_debit',
                'reference_type' => TaskSubmission::class,
                'reference_id' => $submission->id,
                'status' => 'approved',
                'description' => 'Approved campaign completion charge.',
            ], 0, 0);

            if ($platformMargin > 0) {
                $this->walletLedgerService->recordMeta($clientWallet, $platformMargin, [
                    'transaction_type' => 'platform_margin',
                    'reference_type' => TaskSubmission::class,
                    'reference_id' => $submission->id,
                    'status' => 'approved',
                    'description' => 'Platform margin for approved campaign completion.',
                ]);
            }

            $campaign = $submission->assignment?->campaign;
            $campaign?->increment('completed_quantity');
            $campaign?->refresh();

            if (
                $campaign &&
                (int) $campaign->completed_quantity >= (int) $campaign->target_quantity &&
                $campaign->status !== 'completed'
            ) {
                $campaign->update([
                    'status' => 'completed',
                ]);

                $this->notificationService->create(
                    $submission->client_id,
                    'campaign_completed',
                    'Campaign target reached',
                    "{$campaign->title} has reached its approval target and was marked as completed.",
                    [
                        'campaign_id' => $campaign->id,
                    ],
                );
            }

            $funds = $campaign?->funds()->firstOrCreate(
                [
                    'campaign_id' => $submission->assignment->campaign_id,
                    'client_id' => $submission->client_id,
                ],
                [
                    'total_funded' => 0,
                    'total_reserved' => 0,
                    'total_spent' => 0,
                    'total_refunded' => 0,
                ],
            );

            $funds?->update([
                'total_spent' => (float) $funds->total_spent + $clientCharge,
                'total_reserved' => max((float) $funds->total_reserved - $clientCharge, 0),
            ]);

            $submission->freelancer?->freelancerProfile?->increment('total_completed');

            $this->notificationService->create(
                $submission->freelancer_id,
                'submission_approved',
                'Task approved',
                "Your submission for {$submission->assignment?->campaign?->title} was approved.",
                [
                    'submission_id' => $submission->id,
                    'assignment_id' => $submission->assignment_id,
                    'campaign_id' => $submission->assignment?->campaign_id,
                ],
            );

            $this->notificationService->create(
                $submission->client_id,
                'task_completed',
                'Task completed successfully',
                "A completion for {$submission->assignment?->campaign?->title} has been approved.",
                [
                    'submission_id' => $submission->id,
                    'assignment_id' => $submission->assignment_id,
                    'campaign_id' => $submission->assignment?->campaign_id,
                ],
            );
        });

        return back()->with('success', 'Submission approved and wallets updated.');
    }

    public function reject(Request $request, TaskSubmission $submission): RedirectResponse
    {
        if ($submission->status === 'rejected') {
            throw ValidationException::withMessages([
                'submission' => ['This submission has already been rejected.'],
            ]);
        }

        if ($submission->status === 'approved') {
            throw ValidationException::withMessages([
                'submission' => ['Approved submissions cannot be rejected without a separate reversal flow.'],
            ]);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($request, $submission, $validated): void {
            $submission->update([
                'status' => 'rejected',
                'admin_decision' => 'rejected',
                'admin_decision_at' => now(),
                'rejection_reason' => $validated['reason'] ?? 'Rejected by admin review.',
                'final_decision_by' => $request->user()?->id,
            ]);

            $submission->assignment?->update([
                'status' => 'rejected',
                'rejected_at' => now(),
            ]);

            ReviewDecision::query()->create([
                'submission_id' => $submission->id,
                'actor_id' => $request->user()?->id,
                'actor_role' => $request->user()?->role ?? 'admin',
                'decision' => 'rejected',
                'note' => $validated['reason'] ?? 'Rejected from admin review queue.',
            ]);

            $this->notificationService->create(
                $submission->freelancer_id,
                'submission_rejected',
                'Task rejected',
                $validated['reason'] ?? 'Your submission was rejected during admin review.',
                [
                    'submission_id' => $submission->id,
                    'assignment_id' => $submission->assignment_id,
                    'campaign_id' => $submission->assignment?->campaign_id,
                ],
            );
        });

        return back()->with('success', 'Submission rejected.');
    }
}
