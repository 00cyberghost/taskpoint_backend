<?php

namespace App\Http\Controllers\Api;

use App\Actions\Fortify\CreateNewUser;
use App\Exceptions\AccountDeletionBlockedException;
use App\Http\Controllers\Controller;
use App\Models\ClientProfile;
use App\Models\FreelancerProfile;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\EmailVerificationOtpService;
use App\Services\GoogleIdentityService;
use App\Support\ResolvesRequestLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        protected AccountDeletionService $accountDeletionService,
        protected EmailVerificationOtpService $emailVerificationOtpService,
        protected GoogleIdentityService $googleIdentityService,
    ) {
    }

    public function register(Request $request, CreateNewUser $creator): JsonResponse
    {
        $user = $creator->create($request->all());
        $this->emailVerificationOtpService->send($user);
        $token = $user->createToken('freelancer-mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->fresh()->load(['clientProfile', 'freelancerProfile', 'wallets']),
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (in_array($user->status, ['suspended', 'banned'], true)) {
            throw ValidationException::withMessages([
                'email' => ['This account has been suspended. Please contact support.'],
            ]);
        }

        if (! in_array($user->role, ['client', 'freelancer'], true)) {
            throw ValidationException::withMessages([
                'email' => ['This account cannot use the mobile app authentication flow.'],
            ]);
        }

        if ($user->role === 'client') {
            ClientProfile::query()->firstOrCreate(['user_id' => $user->id], ['phone' => $user->phone]);
        }

        if ($user->role === 'freelancer') {
            FreelancerProfile::query()->firstOrCreate(['user_id' => $user->id], ['phone' => $user->phone]);
        }

        $user->forceFill([
            'last_login_ip' => $request->ip(),
            'last_login_country' => ResolvesRequestLocation::country($request),
            'last_seen_at' => now(),
        ])->save();

        $token = $user->createToken($credentials['device_name'] ?? 'freelancer-mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->fresh()->load(['clientProfile', 'freelancerProfile', 'wallets']),
        ]);
    }

    public function google(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id_token' => ['required', 'string'],
            'role' => ['required', 'string', 'in:client,freelancer'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $payload = $this->googleIdentityService->verify($validated['id_token']);

        $user = User::query()
            ->where('google_id', $payload['sub'])
            ->orWhere('email', $payload['email'])
            ->first();

        if ($user && in_array($user->status, ['suspended', 'banned'], true)) {
            throw ValidationException::withMessages([
                'google' => ['This account has been suspended. Please contact support.'],
            ]);
        }

        if ($user && ! in_array($user->role, ['client', 'freelancer'], true)) {
            throw ValidationException::withMessages([
                'google' => ['This Google account cannot use the mobile app authentication flow.'],
            ]);
        }

        if ($user && $user->role !== $validated['role']) {
            throw ValidationException::withMessages([
                'google' => ['This Google account already belongs to a different TaskPoint app role.'],
            ]);
        }

        if (! $user) {
            $this->ensureSocialRegistrationIsAllowed($payload['email'], $request->ip());

            $user = User::query()->create([
                'name' => $payload['name'] ?? Str::before((string) $payload['email'], '@'),
                'email' => $payload['email'],
                'google_id' => $payload['sub'],
                'password' => Str::password(32),
                'role' => $validated['role'],
                'phone' => $validated['phone'] ?? null,
                'registration_ip' => $request->ip(),
                'registration_country' => ResolvesRequestLocation::country($request),
                'last_login_ip' => $request->ip(),
                'last_login_country' => ResolvesRequestLocation::country($request),
                'timezone' => config('app.timezone'),
                'locale' => app()->getLocale(),
                'last_seen_at' => now(),
                'email_verified_at' => now(),
            ]);
        } else {
            $user->forceFill([
                'google_id' => $user->google_id ?: $payload['sub'],
                'name' => $user->name ?: ($payload['name'] ?? $user->name),
                'phone' => $user->phone ?: ($validated['phone'] ?? $user->phone),
                'email_verified_at' => $user->email_verified_at ?? now(),
                'last_login_ip' => $request->ip(),
                'last_login_country' => ResolvesRequestLocation::country($request),
                'last_seen_at' => now(),
            ])->save();
        }

        if ($user->role === 'client') {
            ClientProfile::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['phone' => $validated['phone'] ?? $user->phone],
            );
        }

        if ($user->role === 'freelancer') {
            FreelancerProfile::query()->firstOrCreate(
                ['user_id' => $user->id],
                [
                    'phone' => $validated['phone'] ?? $user->phone,
                    'preferred_countries' => $user->registration_country ? [$user->registration_country] : null,
                ],
            );
        }

        $token = $user->createToken($validated['device_name'] ?? 'google-mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $user->fresh()->load(['clientProfile', 'freelancerProfile', 'wallets']),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()?->load(['clientProfile', 'freelancerProfile', 'wallets']),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()?->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            throw ValidationException::withMessages([
                'account' => ['Authentication is required to delete this account.'],
            ]);
        }

        try {
            $payload = $this->accountDeletionService->delete($user);
        } catch (AccountDeletionBlockedException $exception) {
            throw ValidationException::withMessages([
                'account' => $exception->blockers,
            ]);
        }

        return response()->json($payload);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink([
            'email' => $validated['email'],
        ]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages([
                'email' => [__($status)],
            ]);
        }

        return response()->json([
            'message' => 'Password reset link sent successfully.',
        ]);
    }

    public function resendVerificationEmail(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['Authentication is required to resend verification email.'],
            ]);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Email address is already verified.',
            ]);
        }

        $this->emailVerificationOtpService->send($user);

        return response()->json([
            'message' => 'Verification code sent successfully.',
        ]);
    }

    public function verifyEmailOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'otp' => ['required', 'digits:6'],
        ]);

        $user = $request->user();

        if (! $user) {
            throw ValidationException::withMessages([
                'otp' => ['Authentication is required to verify your email.'],
            ]);
        }

        $this->emailVerificationOtpService->verify($user, $validated['otp']);

        return response()->json([
            'message' => 'Email verified successfully.',
            'user' => $user->fresh()->load(['clientProfile', 'freelancerProfile', 'wallets']),
        ]);
    }

    protected function ensureSocialRegistrationIsAllowed(string $email, ?string $requestIp): void
    {
        $blockedStatuses = ['suspended', 'banned'];

        $blockedByEmail = User::withTrashed()
            ->whereIn('status', $blockedStatuses)
            ->where('email', $email)
            ->exists();

        if ($blockedByEmail) {
            throw ValidationException::withMessages([
                'google' => ['This email address is blocked from registering on the platform.'],
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
                'google' => ['Registrations from this IP address are temporarily blocked.'],
            ]);
        }
    }
}
