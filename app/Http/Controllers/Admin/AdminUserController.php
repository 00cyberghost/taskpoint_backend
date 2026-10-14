<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientProfile;
use App\Models\FreelancerBankChangeRequest;
use App\Models\FreelancerProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin-users', [
            'users' => User::query()
                ->withTrashed()
                ->with([
                    'clientProfile:id,user_id,verification_status',
                    'freelancerProfile:id,user_id,verification_status,payout_status,success_rate,total_completed',
                ])
                ->latest()
                ->paginate(15)
                ->withQueryString(),
            'pendingBankChanges' => FreelancerBankChangeRequest::query()
                ->where('status', 'pending')
                ->with('freelancer:id,name,email')
                ->latest()
                ->get(),
        ]);
    }

    public function updateModeration(Request $request, User $user): RedirectResponse
    {
        abort_if($user->trashed(), 422, 'Deleted accounts cannot be moderated.');

        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:active,suspended,banned'],
            'verification_status' => ['nullable', 'string', 'in:pending,verified,rejected'],
        ]);

        if (array_key_exists('status', $validated) && $validated['status']) {
            $user->update([
                'status' => $validated['status'],
            ]);

            if (in_array($validated['status'], ['suspended', 'banned'], true)) {
                $user->tokens()->delete();
            }
        }

        if (array_key_exists('verification_status', $validated) && $validated['verification_status']) {
            if ($user->role === 'client') {
                ClientProfile::query()->firstOrCreate(['user_id' => $user->id])->update([
                    'verification_status' => $validated['verification_status'],
                ]);
            }

            if ($user->role === 'freelancer') {
                FreelancerProfile::query()->firstOrCreate(['user_id' => $user->id])->update([
                    'verification_status' => $validated['verification_status'],
                ]);
            }
        }

        return back()->with('success', 'User moderation updated successfully.');
    }

    public function reviewBankChange(Request $request, FreelancerBankChangeRequest $bankChangeRequest): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:approved,rejected'],
            'rejection_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($bankChangeRequest->status !== 'pending') {
            return back()->with('info', 'This banking change request has already been reviewed.');
        }

        DB::transaction(function () use ($bankChangeRequest, $validated, $request): void {
            $change = FreelancerBankChangeRequest::query()
                ->lockForUpdate()
                ->findOrFail($bankChangeRequest->id);

            if ($change->status !== 'pending') {
                return;
            }

            if ($validated['status'] === 'approved') {
                $freelancer = $change->freelancer()->firstOrFail();
                $profile = FreelancerProfile::query()->firstOrCreate(
                    ['user_id' => $freelancer->id],
                    ['phone' => $freelancer->phone],
                );

                $profile->update([
                    'bank_name' => $change->bank_name,
                    'bank_code' => $change->bank_code,
                    'account_name' => $change->account_name,
                    'account_number' => $change->account_number,
                    'payout_status' => 'approved',
                ]);
            }

            $change->update([
                'status' => $validated['status'],
                'rejection_reason' => $validated['status'] === 'rejected'
                    ? ($validated['rejection_reason'] ?? 'Banking details were rejected by the admin team.')
                    : null,
                'reviewed_at' => now(),
                'reviewed_by' => $request->user()->id,
            ]);
        });

        return back()->with('success', $validated['status'] === 'approved'
            ? 'Banking change approved. The new account is now active for future payouts.'
            : 'Banking change rejected. The existing payout account remains active.');
    }
}
