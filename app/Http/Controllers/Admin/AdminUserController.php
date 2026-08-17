<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClientProfile;
use App\Models\FreelancerProfile;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
}
