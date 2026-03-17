<?php

namespace App\Http\Controllers\Api;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Controller;
use App\Models\ClientProfile;
use App\Models\FreelancerProfile;
use App\Models\User;
use App\Support\ResolvesRequestLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request, CreateNewUser $creator): JsonResponse
    {
        $user = $creator->create($request->all());
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
}
