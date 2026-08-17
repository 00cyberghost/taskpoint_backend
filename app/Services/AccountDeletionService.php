<?php

namespace App\Services;

use App\Exceptions\AccountDeletionBlockedException;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AccountDeletionService
{
    /**
     * @return array{message: string}
     */
    public function delete(User $user): array
    {
        if (! in_array($user->role, ['client', 'freelancer'], true)) {
            throw new AccountDeletionBlockedException([
                'Only client and freelancer accounts can self-delete.',
            ], 'This account type cannot self-delete.');
        }

        $blockers = $this->blockersFor($user);

        if ($blockers !== []) {
            throw new AccountDeletionBlockedException($blockers);
        }

        DB::transaction(function () use ($user): void {
            $originalEmail = $user->email;
            $deletedEmail = sprintf(
                'deleted-user-%d-%s@deleted.taskpoint.local',
                $user->id,
                now()->timestamp
            );

            $user->tokens()->delete();

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            if (Schema::hasTable('password_reset_tokens')) {
                DB::table('password_reset_tokens')->where('email', $originalEmail)->delete();
            }

            $user->pushTokens()->update([
                'active' => false,
                'token' => 'deleted-token-'.$user->id.'-'.Str::uuid(),
            ]);

            $user->devices()->delete();

            $user->notifications()->update([
                'delivery_status' => 'account_deleted',
            ]);

            $user->clientProfile()?->update([
                'company_name' => 'Deleted Client',
                'phone' => null,
                'avatar' => null,
                'default_country_target' => null,
            ]);

            $user->freelancerProfile()?->update([
                'username' => 'deleted_freelancer_'.$user->id,
                'phone' => null,
                'avatar' => null,
                'bank_name' => null,
                'account_name' => null,
                'account_number' => null,
                'preferred_countries' => null,
            ]);

            $user->forceFill([
                'name' => 'Deleted User #'.$user->id,
                'email' => $deletedEmail,
                'phone' => null,
                'password' => Hash::make(Str::random(64)),
                'remember_token' => null,
                'status' => 'deleted',
                'registration_ip' => null,
                'registration_country' => null,
                'last_login_ip' => null,
                'last_login_country' => null,
                'last_seen_at' => null,
                'email_verified_at' => null,
            ])->save();

            $user->delete();

            AuditLog::query()->create([
                'actor_id' => $user->id,
                'actor_role' => $user->role,
                'action' => 'account_deleted',
                'target_type' => User::class,
                'target_id' => $user->id,
                'before_json' => [
                    'email' => $originalEmail,
                    'role' => $user->role,
                ],
                'after_json' => [
                    'email' => $deletedEmail,
                    'status' => 'deleted',
                ],
                'ip_address' => null,
            ]);
        });

        return [
            'message' => 'Your account has been deleted successfully.',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function blockersFor(User $user): array
    {
        return match ($user->role) {
            'freelancer' => $this->freelancerBlockers($user),
            'client' => $this->clientBlockers($user),
            default => ['This account type cannot self-delete.'],
        };
    }

    /**
     * @return array<int, string>
     */
    protected function freelancerBlockers(User $user): array
    {
        $blockers = [];

        if ($user->assignments()->whereIn('status', ['assigned', 'in_progress', 'submitted'])->exists()) {
            $blockers[] = 'Complete or resolve all assigned and in-progress tasks first.';
        }

        if ($user->submissions()->whereIn('status', ['submitted', 'auto_review', 'client_review', 'admin_review', 'disputed'])->exists()) {
            $blockers[] = 'Wait until your submitted tasks finish review before deleting your account.';
        }

        if (DB::table('withdrawal_requests')->where('freelancer_id', $user->id)->whereIn('status', ['requested', 'under_review', 'processing'])->exists()) {
            $blockers[] = 'Resolve your pending withdrawal requests before deleting your account.';
        }

        if (Dispute::query()
            ->where('status', '!=', 'resolved')
            ->where(function ($query) use ($user): void {
                $query->where('opened_by', $user->id)
                    ->orWhereHas('submission', function ($submissionQuery) use ($user): void {
                        $submissionQuery->where('freelancer_id', $user->id);
                    });
            })
            ->exists()) {
            $blockers[] = 'Resolve your open disputes before deleting your account.';
        }

        return $blockers;
    }

    /**
     * @return array<int, string>
     */
    protected function clientBlockers(User $user): array
    {
        $blockers = [];

        if ($user->clientCampaigns()->whereIn('status', ['submitted', 'priced', 'funded', 'approved_for_distribution', 'active', 'paused'])->exists()) {
            $blockers[] = 'Close or complete your active campaigns before deleting your account.';
        }

        if ($user->clientSubmissions()->whereIn('status', ['submitted', 'auto_review', 'client_review', 'admin_review', 'disputed'])->exists()) {
            $blockers[] = 'Review all pending task submissions before deleting your account.';
        }

        if ($user->clientFundingRequests()->whereIn('status', ['requested', 'under_review'])->exists()) {
            $blockers[] = 'Wait until your funding requests are resolved before deleting your account.';
        }

        if (Dispute::query()
            ->where('status', '!=', 'resolved')
            ->where(function ($query) use ($user): void {
                $query->where('opened_by', $user->id)
                    ->orWhereHas('submission', function ($submissionQuery) use ($user): void {
                        $submissionQuery->where('client_id', $user->id);
                    });
            })
            ->exists()) {
            $blockers[] = 'Resolve your open disputes before deleting your account.';
        }

        return $blockers;
    }
}
