<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignPricing;
use App\Models\CampaignTargetingRule;
use App\Models\ClientCampaignFund;
use App\Models\TaskTypePricing;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WalletLedgerService;
use App\Support\ClientWalletAvailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientCampaignController extends Controller
{
    public function __construct(
        private readonly WalletLedgerService $walletLedgerService,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function dashboard(Request $request): JsonResponse
    {
        $client = $this->client($request);
        $campaigns = $client
            ->clientCampaigns()
            ->with(['pricing', 'funds'])
            ->latest()
            ->take(3)
            ->get();

        $wallet = $this->walletLedgerService->walletFor($client, 'client_main');

        return response()->json([
            'summary' => [
                'active_campaigns' => $client->clientCampaigns()->where('status', 'active')->count(),
                'approved_actions' => $client->clientCampaigns()->sum('completed_quantity'),
                'pending_reviews' => TaskSubmission::query()
                    ->where('client_id', $client->id)
                    ->whereIn('status', ['submitted', 'client_review', 'admin_review'])
                    ->count(),
                'total_reach' => (int) $client->clientCampaigns()->sum('target_quantity'),
                'available_balance' => ClientWalletAvailability::available($client),
            ],
            'wallet' => $wallet,
            'campaigns' => $campaigns,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $client = $this->client($request);

        $campaigns = $client
            ->clientCampaigns()
            ->with(['pricing', 'targetingRule', 'funds'])
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', strtolower((string) $request->string('status')))
            )
            ->latest()
            ->paginate(20);

        return response()->json([
            'campaigns' => $campaigns,
            'available_balance' => ClientWalletAvailability::available($client),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $client = $this->client($request);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'task_type' => ['required', 'string', 'max:100'],
            'target_url' => ['required', 'url', 'max:2048'],
            'target_quantity' => ['required', 'integer', 'min:1'],
            'review_mode' => ['nullable', 'string', 'max:100'],
            'proof_mode' => ['nullable', 'string', 'max:100'],
            'client_unit_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:10'],
            'allowed_countries' => ['nullable', 'array'],
            'allowed_countries.*' => ['string', 'max:2'],
            'instructions' => ['nullable', 'string'],
        ]);

        $campaign = DB::transaction(function () use ($client, $validated) {
            $taskTypePricing = TaskTypePricing::query()
                ->where('task_type', strtolower((string) $validated['task_type']))
                ->where('active', true)
                ->first();

            $unitPrice = (float) ($validated['client_unit_price'] ?? $taskTypePricing?->client_unit_price ?? 150);
            $currency = $validated['currency'] ?? 'NGN';
            $targetQuantity = (int) $validated['target_quantity'];
            $totalFunding = $unitPrice * $targetQuantity;
            $availableBalance = ClientWalletAvailability::available($client);

            if ($availableBalance < $totalFunding) {
                throw ValidationException::withMessages([
                    'balance' => ['Insufficient available wallet balance to reserve this campaign budget.'],
                ]);
            }

            $campaign = Campaign::query()->create([
                'client_id' => $client->id,
                'title' => $validated['title'],
                'description' => $validated['description'] ?? $validated['instructions'] ?? null,
                'task_type' => $validated['task_type'],
                'target_url' => $validated['target_url'],
                'target_quantity' => $targetQuantity,
                'completed_quantity' => 0,
                'status' => 'submitted',
                'review_mode' => $validated['review_mode'] ?? 'hybrid',
                'proof_mode' => $validated['proof_mode'] ?? 'auto_and_manual',
            ]);

            CampaignPricing::query()->create([
                'campaign_id' => $campaign->id,
                'client_unit_price' => $unitPrice,
                'freelancer_unit_payout' => (float) ($taskTypePricing?->freelancer_unit_payout ?? 0),
                'platform_margin' => max($unitPrice - (float) ($taskTypePricing?->freelancer_unit_payout ?? 0), 0),
                'currency' => $taskTypePricing?->currency ?? $currency,
                'payout_minimum_snapshot' => 1000,
            ]);

            CampaignTargetingRule::query()->create([
                'campaign_id' => $campaign->id,
                'allowed_countries' => $validated['allowed_countries'] ?? [],
            ]);

            ClientCampaignFund::query()->create([
                'campaign_id' => $campaign->id,
                'client_id' => $client->id,
                'total_funded' => $totalFunding,
                'total_reserved' => $totalFunding,
                'total_spent' => 0,
                'total_refunded' => 0,
            ]);

            $this->walletLedgerService->recordMeta(
                $this->walletLedgerService->walletFor($client, 'client_main', $currency),
                $totalFunding,
                [
                    'transaction_type' => 'campaign_created',
                    'reference_type' => Campaign::class,
                    'reference_id' => $campaign->id,
                    'status' => 'pending',
                    'description' => sprintf('Campaign %s created and awaiting admin pricing approval.', $campaign->title),
                ],
            );

            return $campaign->load(['pricing', 'targetingRule', 'funds']);
        });

        User::query()
            ->whereIn('role', ['admin', 'super_admin'])
            ->get()
            ->each(function (User $admin) use ($campaign): void {
                $this->notificationService->create(
                    $admin,
                    'campaign_submitted',
                    'New client campaign submitted',
                    sprintf('%s submitted %s for pricing review.', $campaign->client->name, $campaign->title),
                    ['campaign_id' => $campaign->id]
                );
            });

        return response()->json([
            'message' => 'Campaign created successfully.',
            'campaign' => $campaign,
        ], 201);
    }

    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        $client = $this->client($request);
        abort_unless($campaign->client_id === $client->id, 403);

        $campaign->load([
            'pricing',
            'targetingRule',
            'funds',
            'assignments.submission.proofs',
        ]);

        $recentSubmissions = TaskSubmission::query()
            ->where('client_id', $client->id)
            ->whereRelation('assignment', 'campaign_id', $campaign->id)
            ->with(['freelancer', 'proofs'])
            ->latest('submitted_at')
            ->take(10)
            ->get();

        return response()->json([
            'campaign' => $campaign,
            'recent_submissions' => $recentSubmissions,
        ]);
    }
    
    private function client(Request $request): User
    {
        $user = $request->user();
        abort_unless($user?->role === 'client', 403);

        return $user;
    }
}
