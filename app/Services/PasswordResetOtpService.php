<?php

namespace App\Services;

use App\Mail\PasswordResetOtpMail;
use App\Models\PasswordResetOtp;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class PasswordResetOtpService
{
    public function send(User $user): void
    {
        $existingOtp = PasswordResetOtp::query()->where('user_id', $user->id)->first();

        if ($existingOtp && $existingOtp->sent_at?->gt(now()->subSeconds(60))) {
            throw ValidationException::withMessages([
                'email' => ['Please wait a minute before requesting another reset code.'],
            ]);
        }

        $otp = (string) random_int(100000, 999999);

        PasswordResetOtp::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'code_hash' => Hash::make($otp),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
                'sent_at' => now(),
            ],
        );

        Mail::to($user->email)->send(new PasswordResetOtpMail(
            name: $user->name,
            otp: $otp,
        ));
    }

    public function reset(User $user, string $otp, string $password): void
    {
        $record = PasswordResetOtp::query()->where('user_id', $user->id)->first();

        if (! $record) {
            throw ValidationException::withMessages([
                'otp' => ['No reset code was found. Please request a new one.'],
            ]);
        }

        if ($record->expires_at->isPast()) {
            $record->delete();

            throw ValidationException::withMessages([
                'otp' => ['This reset code has expired. Please request a new one.'],
            ]);
        }

        if ($record->attempts >= 5) {
            $record->delete();

            throw ValidationException::withMessages([
                'otp' => ['Too many invalid attempts. Please request a new code.'],
            ]);
        }

        if (! Hash::check($otp, $record->code_hash)) {
            $record->increment('attempts');

            throw ValidationException::withMessages([
                'otp' => ['The reset code you entered is invalid.'],
            ]);
        }

        $user->forceFill([
            'password' => Hash::make($password),
        ])->save();

        $user->tokens()->delete();
        $record->delete();
    }
}
