<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ReviewDecision;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientReviewController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $client = $this->client($request);

        $submissions = TaskSubmission::query()
            ->where('client_id', $client->id)
            ->with(['assignment.campaign.pricing', 'freelancer', 'proofs', 'reviewDecisions'])
            ->when(
                $request->filled('status') && $request->string('status')->toString() !== 'all',
                fn ($query) => $query->where('status', strtolower((string) $request->string('status')))
            )
            ->latest('submitted_at')
            ->paginate(20);

        return response()->json([
            'submissions' => $submissions,
        ]);
    }

    public function approve(Request $request, TaskSubmission $submission): JsonResponse
    {
        $client = $this->client($request);
        abort_unless($submission->client_id === $client->id, 403);

        $submission->update([
            'client_decision' => 'approved',
            'client_decision_at' => now(),
            'status' => 'admin_review',
        ]);

        ReviewDecision::query()->create([
            'submission_id' => $submission->id,
            'actor_id' => $client->id,
            'actor_role' => 'client',
            'decision' => 'approved',
            'note' => $request->input('note'),
        ]);

        $this->notificationService->create(
            $submission->freelancer,
            'client_review_update',
            'Client reviewed your submission',
            'Your submission passed client review and is waiting for final system confirmation.',
            ['submission_id' => $submission->id]
        );

        return response()->json([
            'message' => 'Submission marked for final approval review.',
            'submission' => $submission->fresh(['reviewDecisions']),
        ]);
    }

    public function reject(Request $request, TaskSubmission $submission): JsonResponse
    {
        $client = $this->client($request);
        abort_unless($submission->client_id === $client->id, 403);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $submission->update([
            'client_decision' => 'rejected',
            'client_decision_at' => now(),
            'rejection_reason' => $validated['reason'],
            'status' => 'admin_review',
        ]);

        ReviewDecision::query()->create([
            'submission_id' => $submission->id,
            'actor_id' => $client->id,
            'actor_role' => 'client',
            'decision' => 'rejected',
            'note' => $validated['reason'],
        ]);

        $this->notificationService->create(
            $submission->freelancer,
            'client_review_update',
            'Client requested a rejection review',
            'A client raised a concern on your submission. Admin review may follow.',
            ['submission_id' => $submission->id]
        );

        return response()->json([
            'message' => 'Submission rejection has been recorded for final review.',
            'submission' => $submission->fresh(['reviewDecisions']),
        ]);
    }

    private function client(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->role === 'client', 403);

        return $user;
    }
}
