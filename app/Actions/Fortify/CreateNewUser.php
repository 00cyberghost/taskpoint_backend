<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\ClientProfile;
use App\Models\FreelancerProfile;
use App\Models\User;
use App\Support\ResolvesRequestLocation;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        $requestIp = request()->ip();

        $this->ensureRegistrationIsAllowed($input['email'], $requestIp);

        Validator::make($input, [
            ...$this->profileRules(),
            'role' => ['nullable', 'string', 'in:client,freelancer'],
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            'role' => $input['role'] ?? 'freelancer',
            'registration_ip' => request()->ip(),
            'registration_country' => ResolvesRequestLocation::country(),
            'last_login_ip' => request()->ip(),
            'last_login_country' => ResolvesRequestLocation::country(),
            'timezone' => config('app.timezone'),
            'locale' => app()->getLocale(),
            'last_seen_at' => now(),
        ]);

        if ($user->role === 'client') {
            ClientProfile::query()->create([
                'user_id' => $user->id,
                'phone' => $input['phone'] ?? null,
            ]);
        } else {
            FreelancerProfile::query()->create([
                'user_id' => $user->id,
                'phone' => $input['phone'] ?? null,
                'preferred_countries' => $user->registration_country ? [$user->registration_country] : null,
            ]);
        }

        return $user;
    }

    protected function ensureRegistrationIsAllowed(string $email, ?string $requestIp): void
    {
        $blockedStatuses = ['suspended', 'banned'];

        $blockedByEmail = User::withTrashed()
            ->whereIn('status', $blockedStatuses)
            ->where('email', $email)
            ->exists();

        if ($blockedByEmail) {
            throw ValidationException::withMessages([
                'email' => ['This email address is blocked from registering on the platform.'],
            ]);
        }

        if (! $requestIp) {
            return;
        }

        $blockedByIp = User::withTrashed()
            ->whereIn('status', $blockedStatuses)
            ->where(function ($query) use ($requestIp): void {
                $query->where('registration_ip', $requestIp)
                    ->orWhere('last_login_ip', $requestIp);
            })
            ->exists();

        if ($blockedByIp) {
            throw ValidationException::withMessages([
                'email' => ['Registrations from this IP address are temporarily blocked.'],
            ]);
        }
    }
}
