<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\FraudFlag;
use App\Models\Notification;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use Inertia\Inertia;
use Inertia\Response;

class AdminDashboardController extends Controller
{
    public function index(): Response
    {
        $metrics = [
            'totals' => [
                'clients' => User::query()->where('role', 'client')->count(),
                'freelancers' => User::query()->where('role', 'freelancer')->count(),
                'campaigns' => Campaign::query()->count(),
                'pending_reviews' => TaskSubmission::query()
                    ->whereIn('status', ['submitted', 'client_review', 'admin_review'])
                    ->count(),
            ],
            'finance' => [
                'approved_payouts' => (float) WalletTransaction::query()
                    ->where('transaction_type', 'freelancer_payout')
                    ->where('status', 'approved')
                    ->sum('amount'),
                'client_spend' => (float) WalletTransaction::query()
                    ->where('transaction_type', 'client_campaign_debit')
                    ->where('status', 'approved')
                    ->sum('amount'),
                'platform_revenue' => (float) WalletTransaction::query()
                    ->where('transaction_type', 'platform_margin')
                    ->where('status', 'approved')
                    ->sum('amount'),
                'withdrawals_pending' => WithdrawalRequest::query()
                    ->whereIn('status', ['requested', 'under_review', 'approved'])
                    ->count(),
            ],
        ];

        return Inertia::render('dashboard', [
            'metrics' => $metrics,
            'recentCampaigns' => Campaign::query()
                ->with(['client:id,name', 'pricing:id,campaign_id,client_unit_price,freelancer_unit_payout'])
                ->latest()
                ->take(6)
                ->get(),
            'reviewQueue' => TaskSubmission::query()
                ->with([
                    'assignment.campaign:id,title',
                    'freelancer:id,name',
                    'client:id,name',
                ])
                ->whereIn('status', ['submitted', 'client_review', 'admin_review'])
                ->latest('submitted_at')
                ->take(6)
                ->get(),
            'fraudAlerts' => FraudFlag::query()
                ->with([
                    'user:id,name',
                    'submission.assignment.campaign:id,title',
                ])
                ->latest()
                ->take(6)
                ->get(),
            'recentNotifications' => Notification::query()
                ->with('user:id,name')
                ->latest()
                ->take(6)
                ->get(),
        ]);
    }
}
