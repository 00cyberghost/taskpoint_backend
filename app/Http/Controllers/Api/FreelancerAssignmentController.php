<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FraudFlag;
use App\Models\SubmissionProof;
use App\Models\TaskAssignment;
use App\Models\TaskSession;
use App\Models\TaskSubmission;
use App\Models\TaskTypePricing;
use App\Services\NotificationService;
use App\Services\WalletLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FreelancerAssignmentController extends Controller
{
    public function __construct(
        private readonly NotificationService $notificationService,
        private readonly WalletLedgerService $walletLedgerService,
    ) {}

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();
        $mainWallet = $this->walletLedgerService->walletFor($user, 'freelancer_main');
        $otherWallets = $user->wallets()
            ->where('id', '!=', $mainWallet->id)
            ->get();

        return response()->json([
            'summary' => [
                'assigned' => TaskAssignment::query()->where('freelancer_id', $user->id)->where('status', 'assigned')->count(),
                'in_progress' => TaskAssignment::query()->where('freelancer_id', $user->id)->where('status', 'in_progress')->count(),
                'submitted' => TaskAssignment::query()->where('freelancer_id', $user->id)->where('status', 'submitted')->count(),
                'approved' => TaskAssignment::query()->where('freelancer_id', $user->id)->where('status', 'approved')->count(),
            ],
            'active_assignments' => TaskAssignment::query()
                ->with(['campaign.pricing', 'campaign.client:id,name'])
                ->where('freelancer_id', $user->id)
                ->whereIn('status', ['assigned', 'in_progress'])
                ->latest()
                ->take(5)
                ->get(),
            'task_types' => TaskTypePricing::query()
                ->where('active', true)
                ->orderBy('task_type')
                ->pluck('task_type')
                ->values(),
            'wallets' => collect([$mainWallet->fresh()])->concat($otherWallets)->values(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $assignments = TaskAssignment::query()
            ->with([
                'campaign.client:id,name',
                'campaign.pricing',
                'submission.proofs',
            ])
            ->where('freelancer_id', $request->user()->id)
            ->latest()
            ->paginate(15);

        return response()->json($assignments);
    }

    public function show(Request $request, TaskAssignment $assignment): JsonResponse
    {
        abort_unless($assignment->freelancer_id === $request->user()->id, 403);

        return response()->json([
            'assignment' => $assignment->load([
                'campaign.client:id,name,email',
                'campaign.pricing',
                'campaign.targetingRule',
                'session',
                'submission.proofs',
                'submission.reviewDecisions',
            ]),
        ]);
    }

    public function start(Request $request, TaskAssignment $assignment): JsonResponse
    {
        abort_unless($assignment->freelancer_id === $request->user()->id, 403);

        if (! in_array($assignment->status, ['assigned', 'in_progress'], true)) {
            throw ValidationException::withMessages([
                'assignment' => ['Only assigned tasks can be started.'],
            ]);
        }

        $validated = $request->validate([
            'opened_url' => ['nullable', 'url'],
            'device_metadata' => ['nullable', 'array'],
            'app_state_metadata' => ['nullable', 'array'],
        ]);

        $assignment->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        $session = TaskSession::query()->updateOrCreate(
            ['assignment_id' => $assignment->id],
            [
                'opened_url' => $validated['opened_url'] ?? $assignment->campaign?->target_url,
                'webview_started_at' => now(),
                'device_metadata' => $validated['device_metadata'] ?? null,
                'app_state_metadata' => $validated['app_state_metadata'] ?? null,
            ]
        );

        return response()->json([
            'message' => 'Task started.',
            'assignment' => $assignment->fresh(['campaign', 'session']),
            'session' => $session,
        ]);
    }

    public function submit(Request $request, TaskAssignment $assignment): JsonResponse
    {
        abort_unless($assignment->freelancer_id === $request->user()->id, 403);

        if (! in_array($assignment->status, ['assigned', 'in_progress'], true)) {
            throw ValidationException::withMessages([
                'assignment' => ['Only active assignments can be submitted.'],
            ]);
        }

        $validated = $request->validate([
            'note' => ['nullable', 'string', 'max:2000'],
            'auto_proof' => ['nullable', 'file', 'image', 'max:10240'],
            'manual_proof' => ['nullable', 'file', 'image', 'max:10240'],
            'opened_url' => ['nullable', 'url'],
            'app_state_metadata' => ['nullable', 'array'],
            'device_metadata' => ['nullable', 'array'],
            'screenshot_event_count' => ['nullable', 'integer', 'min:0'],
        ]);

        if (! $request->hasFile('auto_proof') && ! $request->hasFile('manual_proof')) {
            throw ValidationException::withMessages([
                'proof' => ['At least one proof image is required before submission.'],
            ]);
        }

        DB::transaction(function () use ($assignment, $request, $validated): void {
            $assignment->loadMissing(['campaign', 'freelancer']);

            $assignment->update([
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

            $submission = TaskSubmission::query()->updateOrCreate(
                ['assignment_id' => $assignment->id],
                [
                    'freelancer_id' => $assignment->freelancer_id,
                    'client_id' => $assignment->campaign->client_id,
                    'status' => 'client_review',
                    'submitted_at' => now(),
                ]
            );

            if ($request->hasFile('auto_proof')) {
                $path = $request->file('auto_proof')->store('submission-proofs/auto', 'public');

                SubmissionProof::query()->create([
                    'submission_id' => $submission->id,
                    'type' => 'screenshot',
                    'file_path' => $path,
                    'mime_type' => $request->file('auto_proof')?->getMimeType(),
                    'source' => 'auto_capture',
                    'captured_at' => now(),
                    'metadata_json' => [
                        'note' => $validated['note'] ?? null,
                    ],
                ]);
            }

            if ($request->hasFile('manual_proof')) {
                $path = $request->file('manual_proof')->store('submission-proofs/manual', 'public');

                SubmissionProof::query()->create([
                    'submission_id' => $submission->id,
                    'type' => 'screenshot',
                    'file_path' => $path,
                    'mime_type' => $request->file('manual_proof')?->getMimeType(),
                    'source' => 'manual_upload',
                    'captured_at' => now(),
                    'metadata_json' => [
                        'note' => $validated['note'] ?? null,
                    ],
                ]);
            }

            TaskSession::query()->updateOrCreate(
                ['assignment_id' => $assignment->id],
                [
                    'opened_url' => $validated['opened_url'] ?? $assignment->campaign->target_url,
                    'app_state_metadata' => $validated['app_state_metadata'] ?? null,
                    'device_metadata' => $validated['device_metadata'] ?? null,
                    'screenshot_event_count' => $validated['screenshot_event_count'] ?? 0,
                    'webview_ended_at' => now(),
                ]
            );

            if (($validated['screenshot_event_count'] ?? 0) === 0) {
                FraudFlag::query()->create([
                    'user_id' => $assignment->freelancer_id,
                    'assignment_id' => $assignment->id,
                    'submission_id' => $submission->id,
                    'type' => 'missing_screenshot_signal',
                    'severity' => 'low',
                    'status' => 'open',
                    'metadata_json' => [
                        'message' => 'Submission was completed without a recorded screenshot event.',
                    ],
                ]);
            }

            $this->notificationService->create(
                $assignment->campaign->client_id,
                'submission_received',
                'New submission received',
                "A freelancer has submitted proof for {$assignment->campaign->title}.",
                [
                    'assignment_id' => $assignment->id,
                    'submission_id' => $submission->id,
                    'campaign_id' => $assignment->campaign_id,
                ],
            );

            $this->notificationService->create(
                $assignment->freelancer_id,
                'submission_sent',
                'Proof submitted',
                "Your proof for {$assignment->campaign->title} is awaiting review.",
                [
                    'assignment_id' => $assignment->id,
                    'submission_id' => $submission->id,
                    'campaign_id' => $assignment->campaign_id,
                ],
            );
        });

        return response()->json([
            'message' => 'Proof submitted successfully.',
        ]);
    }
}
